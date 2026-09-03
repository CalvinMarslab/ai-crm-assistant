<?php

namespace App\Domain\Notification\Services;

use App\Domain\Notification\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Phase 1 delivers in-app notifications only. Telegram and email arrive in
 * later phases as additional channels behind this same entry point, so callers
 * never talk to a transport directly (SYSTEM_ARCHITECTURE.md section 6).
 */
class Notifier
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function notify(User $user, string $type, string $title, ?string $body = null, array $data = []): AppNotification
    {
        return AppNotification::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data ?: null,
        ]);
    }

    /**
     * @param  iterable<User>  $users
     * @param  array<string, mixed>  $data
     * @return Collection<int, AppNotification>
     */
    public function notifyMany(iterable $users, string $type, string $title, ?string $body = null, array $data = []): Collection
    {
        $sent = collect();

        foreach ($users as $user) {
            $sent->push($this->notify($user, $type, $title, $body, $data));
        }

        return $sent;
    }
}
