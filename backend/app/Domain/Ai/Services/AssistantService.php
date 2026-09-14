<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\Data\LlmMessage;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs one turn of a conversation.
 *
 * The loop is: give the model the history and the tools this user may use;
 * if it asks for read tools, run them and give it the results; repeat until it
 * answers in prose. Write tools never run here — they become pending action
 * requests, and the turn ends with the assistant explaining what it proposes.
 */
class AssistantService
{
    /**
     * Bounded so a model that keeps calling tools cannot loop indefinitely.
     * Four rounds is enough to search, drill into a record and answer.
     */
    private const MAX_TOOL_ROUNDS = 4;

    public function __construct(
        private readonly LlmProvider $provider,
        private readonly ToolRegistry $registry,
        private readonly ActionRequestService $actions,
        private readonly SystemPrompt $prompt,
    ) {}

    public function isAvailable(): bool
    {
        return $this->provider->isConfigured();
    }

    /**
     * @return array{message: AiMessage, action_requests: array<int, \App\Domain\Ai\Models\AiActionRequest>}
     */
    public function reply(User $user, AiConversation $conversation, string $userMessage): array
    {
        if (! $this->provider->isConfigured()) {
            throw ValidationException::withMessages([
                'assistant' => 'The AI assistant is not configured yet. The daily brief still works without it.',
            ]);
        }

        DB::transaction(function () use ($conversation, $userMessage) {
            AiMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => $userMessage,
            ]);

            $conversation->update([
                'last_message_at' => now(),
                'title' => $conversation->title ?? str($userMessage)->limit(60)->toString(),
            ]);
        });

        $tools = $this->registry->definitionsFor($user);
        $history = $this->buildHistory($user, $conversation);
        $proposals = [];

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $reply = $this->provider->chat($history, $tools);

            if (! $reply->hasToolCalls()) {
                return [
                    'message' => $this->persistAssistantMessage($conversation, $reply->content ?? ''),
                    'action_requests' => $proposals,
                ];
            }

            $history[] = $reply;

            $results = [];

            foreach ($reply->toolCalls as $call) {
                [$outcome, $proposal] = $this->handleToolCall($user, $conversation, $call);

                if ($proposal !== null) {
                    $proposals[] = $proposal;
                }

                $results[] = ['call' => $call, 'result' => $outcome];
                $history[] = LlmMessage::toolResult(
                    $call['id'],
                    $call['name'],
                    json_encode($outcome, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                );
            }

            $this->persistToolExchange($conversation, $reply, $results);
        }

        // Out of rounds: say so rather than returning nothing.
        return [
            'message' => $this->persistAssistantMessage(
                $conversation,
                'I looked into that but could not settle on an answer. Could you narrow the question?',
            ),
            'action_requests' => $proposals,
        ];
    }

    /**
     * @param  array<string, mixed>  $call
     * @return array{0: array<string, mixed>, 1: \App\Domain\Ai\Models\AiActionRequest|null}
     */
    private function handleToolCall(User $user, AiConversation $conversation, array $call): array
    {
        $tool = $this->registry->resolveFor($user, $call['name']);

        if ($tool === null) {
            return [['error' => 'No such tool is available to you.'], null];
        }

        // The dividing line of the whole design: reads happen, writes are only
        // ever proposed.
        if (! $tool->isReadOnly()) {
            try {
                $request = $this->actions->propose($user, $tool, $call['arguments'], $conversation->id);
            } catch (Throwable $exception) {
                return [['error' => $exception->getMessage()], null];
            }

            return [[
                'status' => 'awaiting_confirmation',
                'summary' => $request->summary,
                'note' => 'Nothing has been saved. Tell the user what you propose and let them confirm it.',
            ], $request];
        }

        try {
            return [$tool->execute($user, $call['arguments']), null];
        } catch (Throwable $exception) {
            // Handed back as data so the model can correct itself rather than
            // the whole turn collapsing.
            return [['error' => $exception->getMessage()], null];
        }
    }

    /**
     * @return array<int, LlmMessage>
     */
    private function buildHistory(User $user, AiConversation $conversation): array
    {
        $history = [LlmMessage::system($this->prompt->for($user))];

        // Bounded so a long conversation does not grow without limit.
        $recent = $conversation->messages()->latest('id')->limit(30)->get()->reverse();

        foreach ($recent as $message) {
            $history[] = match ($message->role) {
                'user' => LlmMessage::user($message->content ?? ''),
                'assistant' => LlmMessage::assistant($message->content, $message->tool_calls),
                default => LlmMessage::user($message->content ?? ''),
            };
        }

        return $history;
    }

    private function persistAssistantMessage(AiConversation $conversation, string $content): AiMessage
    {
        $message = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $content,
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $message;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function persistToolExchange(AiConversation $conversation, LlmMessage $reply, array $results): void
    {
        AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply->content,
            'tool_calls' => $reply->toolCalls,
            'tool_results' => $results,
        ]);
    }
}
