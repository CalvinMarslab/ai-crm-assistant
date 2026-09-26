<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\Models\AiActionRequest;
use App\Domain\Ai\Services\ActionRequestService;
use App\Domain\Ai\Services\ToolRegistry;
use App\Domain\Identity\Enums\PermissionCode;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HermesWebhookController extends Controller
{
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ActionRequestService $actions,
    ) {}

    public function propose(Request $request): JsonResponse
    {
        $this->verifySignature($request);
        $validated = $request->validate([
            'event_id' => ['required', 'string', 'max:128'],
            'telegram_user_id' => ['required', 'string', 'max:64'],
            'action' => ['required', 'string', 'max:100'],
            'payload' => ['required', 'array'],
        ]);

        return $this->asActor($validated['telegram_user_id'], function (User $user) use ($validated): JsonResponse {
            abort_unless($user->canDo(PermissionCode::AiUse), 403);
            $existing = AiActionRequest::query()
                ->where('source', 'hermes')->where('external_id', $validated['event_id'])->first();

            if ($existing !== null) {
                abort_unless($existing->user_id === $user->id, 409, 'Event ID already belongs to another user.');

                return $this->proposalResponse($existing);
            }

            $tool = $this->tools->resolveFor($user, $validated['action']);
            abort_if($tool === null || $tool->isReadOnly(), 422, 'This Hermes action is not allowed.');

            try {
                $proposal = $this->actions->propose(
                    $user, $tool, $validated['payload'], source: 'hermes', externalId: $validated['event_id'],
                );
            } catch (QueryException) {
                $proposal = AiActionRequest::query()
                    ->where('source', 'hermes')->where('external_id', $validated['event_id'])->firstOrFail();
            }

            return $this->proposalResponse($proposal, 201);
        });
    }

    public function confirm(Request $request, string $uuid): JsonResponse
    {
        $this->verifySignature($request);
        $telegramUserId = $request->validate(['telegram_user_id' => ['required', 'string', 'max:64']])['telegram_user_id'];

        return $this->asActor($telegramUserId, function (User $user) use ($uuid): JsonResponse {
            abort_unless($user->canDo(PermissionCode::AiExecuteWrites), 403);
            $proposal = AiActionRequest::query()->where('uuid', $uuid)->where('source', 'hermes')->firstOrFail();
            $proposal = $this->actions->confirm($user, $proposal);

            return response()->json(['data' => [
                'id' => $proposal->uuid,
                'status' => $proposal->status->value,
                'result' => $proposal->execution_result,
            ]]);
        });
    }

    public function reject(Request $request, string $uuid): JsonResponse
    {
        $this->verifySignature($request);
        $telegramUserId = $request->validate(['telegram_user_id' => ['required', 'string', 'max:64']])['telegram_user_id'];

        return $this->asActor($telegramUserId, function (User $user) use ($uuid): JsonResponse {
            $proposal = AiActionRequest::query()->where('uuid', $uuid)->where('source', 'hermes')->firstOrFail();
            $proposal = $this->actions->reject($user, $proposal);

            return response()->json(['data' => ['id' => $proposal->uuid, 'status' => $proposal->status->value]]);
        });
    }

    private function verifySignature(Request $request): void
    {
        $secret = (string) config('ai.hermes.webhook_secret');
        abort_if($secret === '', 503, 'Hermes webhook is not configured.');
        $timestamp = $request->header('X-Hermes-Timestamp', '');
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= self::MAX_CLOCK_SKEW_SECONDS, 401, 'Expired Hermes request.');
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        $provided = $request->header('X-Hermes-Signature', '');
        abort_unless(hash_equals($expected, str_replace('sha256=', '', $provided)), 401, 'Invalid Hermes signature.');
    }

    private function asActor(string $telegramUserId, callable $callback): JsonResponse
    {
        $user = User::query()->where('telegram_chat_id', $telegramUserId)->where('status', 'active')->firstOrFail();
        OrganizationContext::set($user->organization_id);
        Auth::setUser($user);

        try {
            return $callback($user);
        } finally {
            Auth::forgetUser();
            OrganizationContext::clear();
        }
    }

    private function proposalResponse(AiActionRequest $proposal, int $status = 200): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $proposal->uuid,
            'status' => $proposal->status->value,
            'summary' => $proposal->summary,
            'expires_at' => $proposal->expires_at?->toIso8601String(),
        ]], $status);
    }
}
