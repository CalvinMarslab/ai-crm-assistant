<?php

namespace App\Domain\Integration\Telegram;

use App\Domain\Ai\Exceptions\AssistantUnavailable;
use App\Domain\Ai\Models\AiActionRequest;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Services\ActionRequestService;
use App\Domain\Ai\Services\AssistantService;
use App\Domain\Identity\Enums\PermissionCode;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Validation\ValidationException;
use Throwable;

final class TelegramAssistant
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly AssistantService $assistant,
        private readonly ActionRequestService $actions,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }

        $message = $update['message'] ?? [];
        $text = trim((string) ($message['text'] ?? ''));
        $user = $this->linkedUser($message);

        if ($user === null || $text === '' || str_starts_with($text, '/start')) {
            return;
        }

        $this->withinOrganization($user, function () use ($user, $text, $update): void {
            if (! $user->canDo(PermissionCode::AiUse)) {
                $this->send($user, 'Your CRM role does not allow use of the assistant.');

                return;
            }

            $conversation = AiConversation::query()->firstOrCreate(
                ['user_id' => $user->id, 'title' => 'Telegram'],
                ['organization_id' => $user->organization_id],
            );

            try {
                $outcome = $this->assistant->reply(
                    $user,
                    $conversation,
                    $text,
                    'telegram-'.(string) ($update['update_id'] ?? hash('sha256', $text)),
                );

                $this->send($user, e($outcome['message']->content));

                foreach ($outcome['action_requests'] as $request) {
                    $this->sendAction($user, $request);
                }
            } catch (AssistantUnavailable) {
                $this->send($user, 'The assistant is temporarily unavailable. Please try again later.');
            } catch (ValidationException $exception) {
                $this->send($user, e(collect($exception->errors())->flatten()->first() ?? 'The request could not be processed.'));
            } catch (Throwable) {
                $this->send($user, 'The request could not be processed safely. Please try again.');
            }
        });
    }

    /** @param array<string, mixed> $callback */
    private function handleCallback(array $callback): void
    {
        $user = $this->linkedUser($callback['message'] ?? [], $callback['from'] ?? []);
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');

        if ($user === null || ! preg_match('/^action:(confirm|reject):([0-9a-f-]{36})$/', $data, $matches)) {
            return;
        }

        $this->withinOrganization($user, function () use ($user, $callbackId, $matches): void {
            try {
                $request = AiActionRequest::query()->where('uuid', $matches[2])->firstOrFail();

                if ($matches[1] === 'confirm') {
                    abort_unless($user->canDo(PermissionCode::AiExecuteWrites), 403);
                    $result = $this->actions->confirm($user, $request);
                    $text = $result->status->value === 'executed' ? 'Done — the CRM record was updated.' : 'Action processed.';
                } else {
                    $this->actions->reject($user, $request);
                    $text = 'Cancelled — nothing was changed.';
                }
            } catch (Throwable) {
                $text = 'This action could not be processed. It may have expired or already been handled.';
            }

            $this->client->answerCallbackQuery($callbackId, $text);
            $this->send($user, e($text));
        });
    }

    /** @param array<string, mixed> $message @param array<string, mixed>|null $sender */
    private function linkedUser(array $message, ?array $sender = null): ?User
    {
        $sender ??= $message['from'] ?? [];
        $chat = $message['chat'] ?? [];

        if (($chat['type'] ?? null) !== 'private' || ($sender['is_bot'] ?? true)) {
            return null;
        }

        $chatId = (string) ($chat['id'] ?? '');
        if ($chatId === '' || $chatId !== (string) ($sender['id'] ?? '')) {
            return null;
        }

        return User::query()->where('telegram_chat_id', $chatId)->where('status', 'active')->first();
    }

    private function sendAction(User $user, AiActionRequest $request): void
    {
        $this->client->sendMessage((string) $user->telegram_chat_id, '<b>Confirm CRM change</b>\n\n'.e($request->summary), [
            'inline_keyboard' => [[
                ['text' => '✅ Confirm', 'callback_data' => "action:confirm:{$request->uuid}"],
                ['text' => '❌ Cancel', 'callback_data' => "action:reject:{$request->uuid}"],
            ]],
        ]);
    }

    private function send(User $user, string $text): void
    {
        $this->client->sendMessage((string) $user->telegram_chat_id, $text);
    }

    private function withinOrganization(User $user, callable $callback): void
    {
        OrganizationContext::set($user->organization_id);
        try {
            $callback();
        } finally {
            OrganizationContext::clear();
        }
    }
}
