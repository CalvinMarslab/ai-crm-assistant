<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\Services\DailyBriefService;
use App\Domain\Identity\Enums\PermissionCode;
use App\Domain\Integration\Telegram\TelegramBriefFormatter;
use App\Domain\Integration\Telegram\TelegramChannel;
use App\Domain\Integration\Telegram\TelegramClient;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Linking a user's own Telegram account. Phase 3 is outbound only, so there is
 * no webhook and nothing here accepts commands from Telegram.
 */
class TelegramController extends Controller
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramChannel $channel,
        private readonly DailyBriefService $brief,
        private readonly TelegramBriefFormatter $formatter,
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
            ],
        ]);
    }

    public function link(Request $request): JsonResponse
    {
        $this->authorizeLinking($request);

        $validated = $request->validate([
            // Obtained by the user from the bot; numeric for a private chat.
            'chat_id' => ['required', 'string', 'max:64', 'regex:/^-?\d+$/'],
            'username' => ['nullable', 'string', 'max:64'],
        ]);

        abort_unless($this->client->isConfigured(), 422, 'Telegram is not configured on this server.');

        $user = $request->user();

        $user->forceFill([
            'telegram_chat_id' => $validated['chat_id'],
            'telegram_username' => $validated['username'] ?? null,
            'telegram_linked_at' => now(),
        ])->save();

        // Proves the link works now rather than at nine tomorrow morning.
        $delivered = $this->channel->send(
            $user,
            'Connected',
            'This account is now linked to '.config('app.name').". You'll get your daily brief here.",
        );

        if (! $delivered) {
            $user->forceFill([
                'telegram_chat_id' => null,
                'telegram_username' => null,
                'telegram_linked_at' => null,
            ])->save();

            abort(422, 'Could not reach that chat. Send your bot a message first, then try again.');
        }

        return response()->json(['data' => ['linked' => true, 'username' => $user->telegram_username]]);
    }

    public function unlink(Request $request): JsonResponse
    {
        $this->authorizeLinking($request);

        $request->user()->forceFill([
            'telegram_chat_id' => null,
            'telegram_username' => null,
            'telegram_linked_at' => null,
        ])->save();

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
}
