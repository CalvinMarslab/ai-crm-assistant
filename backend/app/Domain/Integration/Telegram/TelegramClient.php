<?php

namespace App\Domain\Integration\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only place that knows Telegram's HTTP API exists.
 */
class TelegramClient
{
    public function __construct(
        private readonly string $botToken,
        private readonly int $timeout = 15,
    ) {}

    public function isConfigured(): bool
    {
        return $this->botToken !== '';
    }

    public function sendMessage(string $chatId, string $text): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $response = Http::timeout($this->timeout)
            ->asJson()
            ->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                // The brief links back into the CRM; previews would be noise.
                'disable_web_page_preview' => true,
            ]);

        if ($response->failed()) {
            // Never logged with the message body: a brief names customers.
            Log::warning('Telegram delivery failed', [
                'status' => $response->status(),
                'description' => $response->json('description'),
            ]);

            return false;
        }

        return true;
    }
}
