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

    /** @param array<string, mixed>|null $replyMarkup */
    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, 4096),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        $response = Http::timeout($this->timeout)
            ->asJson()
            ->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", $payload);

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

    public function answerCallbackQuery(string $callbackQueryId, string $text): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        return Http::timeout($this->timeout)->asJson()
            ->post("https://api.telegram.org/bot{$this->botToken}/answerCallbackQuery", [
                'callback_query_id' => $callbackQueryId,
                'text' => mb_substr($text, 0, 200),
            ])->successful();
    }
}
