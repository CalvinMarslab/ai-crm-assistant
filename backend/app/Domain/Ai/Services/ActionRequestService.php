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
 */
class ActionRequestService
{
    /** A proposal goes stale rather than waiting indefinitely to be accepted. */
    private const LIFETIME_HOURS = 24;

    public function __construct(private readonly ToolRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function propose(User $user, AiTool $tool, array $arguments, ?int $conversationId = null): AiActionRequest
    {
        return AiActionRequest::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
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
     * Permission is re-checked here rather than trusted from proposal time:
     * minutes may have passed, and the user's access or the record itself may
     * have moved on.
     */
    public function confirm(User $user, AiActionRequest $request): AiActionRequest
    {
        $this->guardActionable($user, $request);

        $tool = $this->registry->resolveFor($user, $request->action_name);

        if ($tool === null) {
            throw ValidationException::withMessages([
                'action' => 'You are no longer allowed to perform this action.',
            ]);
        }

        try {
            $result = DB::transaction(fn () => $tool->execute($user, $request->action_payload));

            $request->update([
                'status' => ActionRequestStatus::Executed,
                'confirmed_at' => now(),
                'executed_at' => now(),
                'execution_result' => $result,
            ]);
        } catch (Throwable $exception) {
            // The failure is recorded against the request rather than lost, so
            // the user can see the action did not happen and why.
            Log::warning('AI action failed', [
                'action' => $request->action_name,
                'request' => $request->uuid,
            ]);

            $request->update([
                'status' => ActionRequestStatus::Failed,
                'confirmed_at' => now(),
                'execution_result' => ['error' => $exception->getMessage()],
            ]);

            throw $exception;
        }

        return $request->fresh();
    }

    public function reject(User $user, AiActionRequest $request): AiActionRequest
    {
        $this->guardOwnership($user, $request);

        if (! $request->status->isOpen()) {
            throw ValidationException::withMessages(['action' => 'This action is no longer pending.']);
        }

        $request->update(['status' => ActionRequestStatus::Rejected]);

        return $request->fresh();
    }

    private function guardActionable(User $user, AiActionRequest $request): void
    {
        $this->guardOwnership($user, $request);

        if ($request->hasExpired()) {
            $request->update(['status' => ActionRequestStatus::Expired]);

            throw ValidationException::withMessages([
                'action' => 'This suggestion has expired. Ask the assistant again.',
            ]);
        }

        if (! $request->status->isOpen()) {
            throw ValidationException::withMessages([
                'action' => 'This action has already been '.$request->status->value.'.',
            ]);
        }
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
