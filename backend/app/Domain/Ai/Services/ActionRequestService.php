<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Contracts\AiTool;
use App\Domain\Ai\Enums\ActionRequestStatus;
use App\Domain\Ai\Models\AiActionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The confirmation step between the assistant proposing a change and the
 * change happening.
 *
 * Confirm is atomic and idempotent: concurrent or replayed requests produce
 * exactly one domain mutation and one set of audit/activity side effects.
 */
class ActionRequestService
{
    /** A proposal goes stale rather than waiting indefinitely to be accepted. */
    private const LIFETIME_HOURS = 24;

    public function __construct(private readonly ToolRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function propose(User $user, AiTool $tool, array $arguments, ?int $conversationId = null, ?string $turnId = null, string $source = 'assistant', ?string $externalId = null): AiActionRequest
    {
        return AiActionRequest::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'turn_id' => $turnId,
            'source' => $source,
            'external_id' => $externalId,
            'action_name' => $tool->definition()->name,
            'action_payload' => $arguments,
            // Written now, while the referenced records still say what they
            // said when the assistant read them.
            'summary' => $this->safeSummary($tool, $user, $arguments),
            'status' => ActionRequestStatus::Pending,
            'confirmation_required' => true,
            'expires_at' => now()->addHours(self::LIFETIME_HOURS),
        ]);
    }

    /**
     * Runs the proposed change, as the confirming user.
     *
     * Atomic and idempotent: the row is locked for update inside a transaction.
     * If the request was already executed (replay), the previous result is
     * returned without re-running the tool. If execution fails, the status is
     * set to Failed within the same transaction so it cannot be replayed.
     *
     * Permission is re-checked here rather than trusted from proposal time:
     * minutes may have passed, and the user's access or the record itself may
     * have moved on.
     */
    public function confirm(User $user, AiActionRequest $request): AiActionRequest
    {
        $executionStarted = false;

        try {
            $confirmed = DB::transaction(function () use ($user, $request, &$executionStarted) {
                $request = AiActionRequest::lockForUpdate()->findOrFail($request->id);

                $this->guardOwnership($user, $request);

                if ($request->status === ActionRequestStatus::Executed) {
                    return $request;
                }

                if (! $request->status->isOpen()) {
                    throw ValidationException::withMessages([
                        'action' => 'This action has already been '.$request->status->value.'.',
                    ]);
                }

                if ($request->hasExpired()) {
                    $request->update(['status' => ActionRequestStatus::Expired]);

                    return $request->fresh();
                }

                $tool = $this->registry->resolveFor($user, $request->action_name);

                if ($tool === null) {
                    throw ValidationException::withMessages([
                        'action' => 'You are no longer allowed to perform this action.',
                    ]);
                }

                $executionStarted = true;
                $result = $tool->execute($user, $request->action_payload);

                $request->update([
                    'status' => ActionRequestStatus::Executed,
                    'confirmed_at' => now(),
                    'executed_at' => now(),
                    'execution_result' => $result,
                ]);

                return $request->fresh();
            });
        } catch (Throwable $exception) {
            if ($executionStarted) {
                DB::transaction(function () use ($request): void {
                    $failed = AiActionRequest::lockForUpdate()->findOrFail($request->id);

                    if ($failed->status === ActionRequestStatus::Pending) {
                        $failed->update([
                            'status' => ActionRequestStatus::Failed,
                            'confirmed_at' => now(),
                            'execution_result' => ['error' => 'The action could not be completed.'],
                        ]);
                    }
                });

                // Log exception class only — never the message which may contain secrets.
                Log::warning('AI action failed', [
                    'action' => $request->action_name,
                    'request' => $request->uuid,
                    'error_class' => get_class($exception),
                ]);

                // Never rethrow the original exception — it may carry secrets.
                // Return a stable generic error instead.
                throw ValidationException::withMessages([
                    'action' => 'The action could not be completed.',
                ]);
            }

            // Non-execution exceptions (ownership, status guards) are safe to rethrow
            // because they originate from our own validation and contain no secrets.
            throw $exception;
        }

        if ($confirmed->status === ActionRequestStatus::Expired) {
            throw ValidationException::withMessages([
                'action' => 'This suggestion has expired. Ask the assistant again.',
            ]);
        }

        return $confirmed;
    }

    public function reject(User $user, AiActionRequest $request): AiActionRequest
    {
        return DB::transaction(function () use ($user, $request) {
            $request = AiActionRequest::lockForUpdate()->findOrFail($request->id);

            $this->guardOwnership($user, $request);

            if (! $request->status->isOpen()) {
                throw ValidationException::withMessages(['action' => 'This action is no longer pending.']);
            }

            $request->update(['status' => ActionRequestStatus::Rejected]);

            return $request->fresh();
        });
    }

    /**
     * Only the person the assistant proposed it to may act on it — a
     * confirmation is not transferable.
     */
    private function guardOwnership(User $user, AiActionRequest $request): void
    {
        abort_unless($request->user_id === $user->id, 403, 'This action was not proposed to you.');
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function safeSummary(AiTool $tool, User $user, array $arguments): string
    {
        try {
            return $tool->summarise($user, $arguments);
        } catch (Throwable) {
            // A summary that cannot be built usually means the arguments point
            // at something missing; the request still records what was asked.
            return $tool->definition()->name.' with '.json_encode($arguments);
        }
    }
}
