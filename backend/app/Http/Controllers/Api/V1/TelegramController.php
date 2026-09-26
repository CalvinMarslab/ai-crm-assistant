<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\Services\DailyBriefService;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Integration\Telegram\TelegramAssistant;
use App\Domain\Integration\Telegram\TelegramBriefFormatter;
use App\Domain\Integration\Telegram\TelegramChannel;
use App\Domain\Integration\Telegram\TelegramClient;
use App\Domain\Integration\Telegram\TelegramLinkToken;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Linking a user's own Telegram account via expiring, single-use link tokens.
 *
 * Flow: authenticated user requests a token -> receives a t.me deep link ->
 * sends /start TOKEN from their Telegram account -> inbound webhook verifies
 * the token and establishes the link. This replaces arbitrary chat_id pasting.
 */
class TelegramController extends Controller
{
    /** Link tokens expire after this many minutes. */
    private const TOKEN_LIFETIME_MINUTES = 10;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramChannel $channel,
        private readonly DailyBriefService $brief,
        private readonly TelegramBriefFormatter $formatter,
        private readonly TelegramAssistant $telegramAssistant,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $this->authorizeLinking($request);

        $user = $request->user();

        return response()->json([
            'data' => [
                'configured' => $this->client->isConfigured(),
                'linked' => filled($user->telegram_chat_id),
                'username' => $user->telegram_username,
                'linked_at' => $user->telegram_linked_at?->toIso8601String(),
                'bot_username' => $this->client->isConfigured() ? config('ai.telegram.bot_username') : null,
            ],
        ]);
    }

    /**
     * Authenticated user requests an expiring link token. Returns a deep link
     * that the user opens in Telegram to prove account ownership.
     */
    public function requestLinkToken(Request $request): JsonResponse
    {
        $this->authorizeLinking($request);

        abort_unless($this->client->isConfigured(), 422, 'Telegram is not configured on this server.');

        $botUsername = config('ai.telegram.bot_username');
        abort_unless(filled($botUsername), 422, 'Telegram bot username is not configured.');

        $user = $request->user();

        // Expire any outstanding tokens for this user.
        TelegramLinkToken::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['expires_at' => now()]);

        $rawToken = TelegramLinkToken::generateToken();

        $token = TelegramLinkToken::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'token_hash' => TelegramLinkToken::hashToken($rawToken),
            'expires_at' => now()->addMinutes(self::TOKEN_LIFETIME_MINUTES),
        ]);

        return response()->json([
            'data' => [
                'deep_link' => "https://t.me/{$botUsername}?start={$rawToken}",
                'expires_at' => $token->expires_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Telegram webhook endpoint. Accepts updates from Telegram when a user
     * sends /start TOKEN to the bot. This is the only way to link an account.
     *
     * Route is outside auth:sanctum middleware — authentication is via the
     * webhook secret header instead.
     */
    public function webhook(Request $request): JsonResponse
    {
        $this->verifyWebhookSecret($request);

        $update = $request->all();
        $text = $update['message']['text'] ?? '';

        // Linked users may ask the assistant questions or confirm proposed
        // writes. The assistant handler independently proves chat ownership.
        if (! str_starts_with($text, '/start ')) {
            $this->telegramAssistant->handle($update);

            return response()->json(['ok' => true]);
        }

        $rawToken = trim(substr($text, 7));

        if ($rawToken === '') {
            return response()->json(['ok' => true]);
        }

        // Reject non-private chats (groups, channels, supergroups).
        $chatType = $update['message']['chat']['type'] ?? '';
        if ($chatType !== 'private') {
            return response()->json(['ok' => true]);
        }

        // Reject bot senders.
        $isBot = $update['message']['from']['is_bot'] ?? true;
        if ($isBot) {
            return response()->json(['ok' => true]);
        }

        $chatId = (string) ($update['message']['chat']['id'] ?? '');
        $fromId = (string) ($update['message']['from']['id'] ?? '');
        $username = $update['message']['from']['username'] ?? null;

        if ($chatId === '' || $fromId === '') {
            return response()->json(['ok' => true]);
        }

        // In a private chat the sender must be the chat owner.
        if ($chatId !== $fromId) {
            return response()->json(['ok' => true]);
        }

        return DB::transaction(function () use ($rawToken, $chatId, $username) {
            $token = TelegramLinkToken::where('token_hash', TelegramLinkToken::hashToken($rawToken))
                ->lockForUpdate()
                ->first();

            // Invalid, expired, or already used — safe replay: do nothing.
            if ($token === null || ! $token->isRedeemable()) {
                return response()->json(['ok' => true]);
            }

            // Chat uniqueness: this chat_id must not already be linked to
            // another user in any tenant.
            $existingUser = User::where('telegram_chat_id', $chatId)->first();

            if ($existingUser !== null && $existingUser->id !== $token->user_id) {
                Log::warning('Telegram link rejected: chat already linked to another user', [
                    'chat_id' => $chatId,
                    'existing_user_id' => $existingUser->id,
                    'requesting_user_id' => $token->user_id,
                ]);

                // Mark token as used so it cannot be replayed.
                $token->update(['used_at' => now()]);

                $this->client->sendMessage(
                    $chatId,
                    'This Telegram account is already linked to another CRM user. Unlink it there first.',
                );

                return response()->json(['ok' => true]);
            }

            // Consume the token.
            $token->update([
                'used_at' => now(),
                'linked_chat_id' => $chatId,
            ]);

            // Link the user's account. Use updateOrFail wrapped in try/catch
            // to safely handle duplicate-key race on telegram_chat_id unique index.
            $user = User::findOrFail($token->user_id);

            try {
                $user->forceFill([
                    'telegram_chat_id' => $chatId,
                    'telegram_username' => $username,
                    'telegram_linked_at' => now(),
                ])->save();
            } catch (QueryException $e) {
                // Duplicate key on telegram_chat_id — another request won the race.
                Log::warning('Telegram link duplicate-key race', [
                    'chat_id' => $chatId,
                    'user_id' => $user->id,
                ]);

                return response()->json(['ok' => true]);
            }

            // Audit the linking action.
            app(AuditRecorder::class)->record('telegram.linked', $user, null, [
                'chat_id' => $chatId,
                'username' => $username,
            ]);

            // Confirm to the user in Telegram.
            $this->client->sendMessage(
                $chatId,
                'Your Telegram account is now linked to '.config('app.name')
                    .". You'll receive your daily brief here.",
            );

            return response()->json(['ok' => true]);
        });
    }

    public function unlink(Request $request): JsonResponse
    {
        $this->authorizeLinking($request);

        $user = $request->user();
        $previousChatId = $user->telegram_chat_id;

        $user->forceFill([
            'telegram_chat_id' => null,
            'telegram_username' => null,
            'telegram_linked_at' => null,
        ])->save();

        // Audit the unlinking.
        app(AuditRecorder::class)->record('telegram.unlinked', $user, [
            'chat_id' => $previousChatId,
        ], null);

        return response()->json(['data' => ['linked' => false]]);
    }

    /** Sends the brief now, so the user can see what they will receive. */
    public function sendTestBrief(Request $request): JsonResponse
    {
        $this->authorizeLinking($request);

        $user = $request->user();

        abort_unless($this->channel->canReach($user), 422, 'Link your Telegram account first.');

        $delivered = $this->channel->send(
            $user,
            'Your daily brief',
            $this->formatter->format($this->brief->for($user)),
        );

        return response()->json(['data' => ['delivered' => $delivered]]);
    }

    private function authorizeLinking(Request $request): void
    {
        abort_unless($request->user()->canDo(PermissionCode::IntegrationTelegramLink), 403);
    }

    private function verifyWebhookSecret(Request $request): void
    {
        $secret = config('ai.telegram.webhook_secret');

        abort_unless(
            filled($secret) && hash_equals($secret, $request->header('X-Telegram-Bot-Api-Secret-Token', '')),
            403,
            'Invalid webhook secret.',
        );
    }
}
