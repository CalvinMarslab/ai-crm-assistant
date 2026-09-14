<?php

namespace App\Domain\Integration\Telegram;

use App\Domain\Integration\Contracts\NotificationChannel;
use App\Models\User;

class TelegramChannel implements NotificationChannel
{
    public function __construct(private readonly TelegramClient $client) {}

    public function name(): string
    {
        return 'telegram';
    }

    /**
     * Two conditions: the bot exists, and this user linked their account.
     * Notifications can therefore only ever reach a chat the user connected
     * themselves (TELEGRAM_INTEGRATION.md, Security).
     */
    public function canReach(User $user): bool
    {
        return $this->client->isConfigured() && filled($user->telegram_chat_id);
    }

    public function send(User $user, string $title, string $body, array $context = []): bool
    {
        if (! $this->canReach($user)) {
            return false;
        }

        $text = '<b>'.e($title).'</b>';

        if ($body !== '') {
            $text .= "\n\n".$body;
        }

        return $this->client->sendMessage($user->telegram_chat_id, $text);
    }
}
