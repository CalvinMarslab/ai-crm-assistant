<?php

namespace App\Domain\Integration\Contracts;

use App\Models\User;

/**
 * An outbound channel the system can reach a user through.
 *
 * SYSTEM_ARCHITECTURE.md section 6: business logic talks to this, never to a
 * third-party API. Telegram is the first implementation; email and the rest
 * arrive in later phases behind the same interface.
 */
interface NotificationChannel
{
    public function name(): string;

    /** Whether the channel is configured and the user has connected to it. */
    public function canReach(User $user): bool;

    /**
     * @param  array<string, mixed>  $context
     * @return bool  Whether it was delivered.
     */
    public function send(User $user, string $title, string $body, array $context = []): bool;
}
