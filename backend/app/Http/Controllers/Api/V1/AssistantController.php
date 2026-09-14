<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\Models\AiActionRequest;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Services\ActionRequestService;
use App\Domain\Ai\Services\AssistantService;
use App\Domain\Ai\Services\DailyBriefService;
use App\Domain\Identity\Enums\PermissionCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiActionRequestResource;
use App\Http\Resources\AiMessageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssistantController extends Controller
{
    public function __construct(
        private readonly AssistantService $assistant,
        private readonly ActionRequestService $actions,
        private readonly DailyBriefService $brief,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $this->authorizeAssistant($request);

        return response()->json([
            'data' => [
                // The chat needs a model; the brief does not. Told apart so the
                // client can offer the brief on an unconfigured deployment.
                'chat_available' => $this->assistant->isAvailable(),
                'brief_available' => true,
                'can_execute_writes' => $request->user()->canDo(PermissionCode::AiExecuteWrites),
            ],
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $this->authorizeAssistant($request);

        $conversations = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->get(['uuid', 'title', 'last_message_at', 'created_at']);

        return response()->json([
            'data' => $conversations->map(fn (AiConversation $c) => [
                'id' => $c->uuid,
                'title' => $c->title ?? 'New conversation',
                'last_message_at' => $c->last_message_at?->toIso8601String(),
            ]),
        ]);
    }

    public function startConversation(Request $request): JsonResponse
    {
        $this->authorizeAssistant($request);

        $conversation = AiConversation::create([
            'organization_id' => $request->user()->organization_id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => ['id' => $conversation->uuid, 'title' => null]], 201);
    }

    public function messages(Request $request, string $uuid): AnonymousResourceCollection
    {
        $conversation = $this->ownConversation($request, $uuid);

        return AiMessageResource::collection(
            // Tool exchanges are kept for audit but are not conversation the
            // user needs to read.
            $conversation->messages()->whereIn('role', ['user', 'assistant'])->whereNotNull('content')->get()
        );
    }

    public function send(Request $request, string $uuid): JsonResponse
    {
        $conversation = $this->ownConversation($request, $uuid);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
        ]);

        $outcome = $this->assistant->reply($request->user(), $conversation, $validated['message']);

        return response()->json([
            'data' => [
                'message' => new AiMessageResource($outcome['message']),
                'action_requests' => AiActionRequestResource::collection($outcome['action_requests']),
            ],
        ]);
    }

    public function deleteConversation(Request $request, string $uuid): JsonResponse
    {
        $this->ownConversation($request, $uuid)->delete();

        return response()->json(null, 204);
    }

    // ---- Pending changes ----

    public function actionRequests(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAssistant($request);

        $requests = AiActionRequest::query()
            ->where('user_id', $request->user()->id)
            ->when($request->boolean('pending'), fn ($q) => $q->pending())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return AiActionRequestResource::collection($requests);
    }

    public function confirmAction(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAssistant($request);

        abort_unless($request->user()->canDo(PermissionCode::AiExecuteWrites), 403);

        $actionRequest = AiActionRequest::query()->where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'data' => new AiActionRequestResource($this->actions->confirm($request->user(), $actionRequest)),
        ]);
    }

    public function rejectAction(Request $request, string $uuid): JsonResponse
    {
        $this->authorizeAssistant($request);

        $actionRequest = AiActionRequest::query()->where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'data' => new AiActionRequestResource($this->actions->reject($request->user(), $actionRequest)),
        ]);
    }

    // ---- Daily brief ----

    public function dailyBrief(Request $request): JsonResponse
    {
        $this->authorizeAssistant($request);

        return response()->json(['data' => $this->brief->for($request->user())]);
    }

    private function authorizeAssistant(Request $request): void
    {
        abort_unless($request->user()->canDo(PermissionCode::AiUse), 403);
    }

    private function ownConversation(Request $request, string $uuid): AiConversation
    {
        $this->authorizeAssistant($request);

        $conversation = AiConversation::query()->where('uuid', $uuid)->firstOrFail();

        // Conversations are private to the person who had them.
        abort_unless($conversation->belongsToUser($request->user()), 403);

        return $conversation;
    }
}
