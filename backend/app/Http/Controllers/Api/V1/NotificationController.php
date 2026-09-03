<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notification\Models\AppNotification;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $notifications = $this->ownQuery($request)
            ->when($request->boolean('unread'), fn (Builder $q) => $q->unread())
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return NotificationResource::collection($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['count' => $this->ownQuery($request)->unread()->count()]]);
    }

    public function markRead(Request $request, string $uuid): JsonResponse
    {
        $notification = $this->ownQuery($request)->whereUuid($uuid)->firstOrFail();
        $notification->update(['read_at' => now()]);

        return response()->json(['data' => new NotificationResource($notification)]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->ownQuery($request)->unread()->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    /** A user only ever reads their own notifications. */
    private function ownQuery(Request $request): Builder
    {
        return AppNotification::query()->where('user_id', $request->user()->id);
    }
}
