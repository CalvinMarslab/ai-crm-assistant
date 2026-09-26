<?php

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\Data\LlmMessage;
use App\Domain\Ai\Exceptions\AssistantUnavailable;
use App\Domain\Ai\Models\AiActionRequest;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiMessage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs one turn of a conversation.
 *
 * The loop is: give the model the history and the tools this user may use;
 * if it asks for read tools, run them and give it the results; repeat until it
 * answers in prose. Write tools never run here — they become pending action
 * requests, and the turn ends with the assistant explaining what it proposes.
 *
 * Idempotency: callers pass an idempotency_key; if a user message with that
 * key already exists in the conversation, the existing assistant reply is
 * returned without re-running the turn.
 *
 * Provider errors are mapped to a sanitized AssistantUnavailable exception
 * that never leaks provider bodies, credentials, or customer content.
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
     * @return array{message: AiMessage, action_requests: array<int, AiActionRequest>}
     */
    public function reply(User $user, AiConversation $conversation, string $userMessage, ?string $idempotencyKey = null): array
    {
        if (! $this->provider->isConfigured()) {
            throw ValidationException::withMessages([
                'assistant' => 'The AI assistant is not configured yet. The daily brief still works without it.',
            ]);
        }

        // Every turn gets its own identifier so replay can find exactly the
        // outputs produced by this turn and never a later one.
        $turnId = (string) Str::uuid();

        // Atomically claim the idempotency key (if provided) via INSERT.
        // On duplicate key: check content match, return completed reply or conflict.
        if ($idempotencyKey !== null) {
            $claimed = $this->atomicClaimKey($conversation, $userMessage, $idempotencyKey, $turnId);

            if ($claimed !== null) {
                return $claimed;
            }
        } else {
            // No idempotency key — just insert normally.
            DB::transaction(function () use ($conversation, $userMessage, $turnId) {
                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'user',
                    'content' => $userMessage,
                    'turn_id' => $turnId,
                ]);

                $conversation->update([
                    'last_message_at' => now(),
                    'title' => $conversation->title ?? str($userMessage)->limit(60)->toString(),
                ]);
            });
        }

        try {
            return $this->runTurn($user, $conversation, $turnId);
        } catch (AssistantUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Map any provider/connection/timeout/malformed errors to a
            // sanitized 503. Never leak provider bodies, credentials, or
            // customer content in the response.
            Log::warning('AI turn failed', [
                'conversation' => $conversation->uuid,
                'error_class' => get_class($exception),
            ]);

            throw AssistantUnavailable::providerError(503);
        }
    }

    /**
     * Atomically claim an idempotency key. Returns null if the key was freshly
     * claimed (caller should proceed with the turn). Returns the cached reply
     * if the key was already processed. Throws on content mismatch or in-progress.
     *
     * @return array{message: AiMessage, action_requests: array<int, AiActionRequest>}|null
     */
    private function atomicClaimKey(AiConversation $conversation, string $userMessage, string $idempotencyKey, string $turnId): ?array
    {
        try {
            DB::transaction(function () use ($conversation, $userMessage, $idempotencyKey, $turnId) {
                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'user',
                    'content' => $userMessage,
                    'idempotency_key' => $idempotencyKey,
                    'turn_id' => $turnId,
                ]);

                $conversation->update([
                    'last_message_at' => now(),
                    'title' => $conversation->title ?? str($userMessage)->limit(60)->toString(),
                ]);
            });

            // Key freshly claimed — caller proceeds with the turn.
            return null;
        } catch (QueryException $e) {
            // Duplicate key — the idempotency key already exists.
            $existing = AiMessage::where('conversation_id', $conversation->id)
                ->where('idempotency_key', $idempotencyKey)
                ->where('role', 'user')
                ->first();

            if ($existing === null) {
                throw $e;
            }

            // Content mismatch: same key, different payload — conflict.
            if ($existing->content !== $userMessage) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'This idempotency key was already used with different content.',
                ]);
            }

            // Check for a completed reply.
            $reply = $this->findCompletedReply($conversation, $existing);

            if ($reply !== null) {
                return $reply;
            }

            // Turn is still in progress — stable response.
            throw ValidationException::withMessages([
                'idempotency_key' => 'This request is still being processed. Please wait.',
            ]);
        }
    }

    /**
     * @return array{message: AiMessage, action_requests: array<int, AiActionRequest>}
     */
    private function runTurn(User $user, AiConversation $conversation, string $turnId): array
    {
        $tools = $this->registry->definitionsFor($user);
        $history = $this->buildHistory($user, $conversation);
        $proposals = [];

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $reply = $this->provider->chat($history, $tools);

            if (! $reply->hasToolCalls()) {
                return [
                    'message' => $this->persistAssistantMessage($conversation, $reply->content ?? '', $turnId),
                    'action_requests' => $proposals,
                ];
            }

            $history[] = $reply;

            $results = [];

            foreach ($reply->toolCalls as $call) {
                [$outcome, $proposal] = $this->handleToolCall($user, $conversation, $call, $turnId);

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

            $this->persistToolExchange($conversation, $reply, $results, $turnId);
        }

        // Out of rounds: say so rather than returning nothing.
        return [
            'message' => $this->persistAssistantMessage(
                $conversation,
                'I looked into that but could not settle on an answer. Could you narrow the question?',
                $turnId,
            ),
            'action_requests' => $proposals,
        ];
    }

    /**
     * Given an existing user message, find the completed assistant reply that
     * belongs to the same turn (matched by turn_id), never a later turn's output.
     *
     * @return array{message: AiMessage, action_requests: array<int, AiActionRequest>}|null
     */
    private function findCompletedReply(AiConversation $conversation, AiMessage $existingUserMessage): ?array
    {
        $turnId = $existingUserMessage->turn_id;

        if ($turnId === null) {
            return null;
        }

        $assistantReply = AiMessage::where('conversation_id', $conversation->id)
            ->where('turn_id', $turnId)
            ->where('role', 'assistant')
            ->whereNotNull('content')
            ->whereNull('tool_calls')
            ->first();

        if ($assistantReply === null) {
            return null;
        }

        $actionRequests = $conversation->actionRequests()
            ->where('turn_id', $turnId)
            ->get()
            ->all();

        return [
            'message' => $assistantReply,
            'action_requests' => $actionRequests,
        ];
    }

    /**
     * @param  array<string, mixed>  $call
     * @return array{0: array<string, mixed>, 1: AiActionRequest|null}
     */
    private function handleToolCall(User $user, AiConversation $conversation, array $call, string $turnId): array
    {
        $tool = $this->registry->resolveFor($user, $call['name']);

        if ($tool === null) {
            return [['error' => 'No such tool is available to you.'], null];
        }

        // The dividing line of the whole design: reads happen, writes are only
        // ever proposed.
        if (! $tool->isReadOnly()) {
            try {
                $request = $this->actions->propose($user, $tool, $call['arguments'], $conversation->id, $turnId);
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

    private function persistAssistantMessage(AiConversation $conversation, string $content, string $turnId): AiMessage
    {
        $message = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $content,
            'turn_id' => $turnId,
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $message;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     */
    private function persistToolExchange(AiConversation $conversation, LlmMessage $reply, array $results, string $turnId): void
    {
        AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply->content,
            'tool_calls' => $reply->toolCalls,
            'tool_results' => $results,
            'turn_id' => $turnId,
        ]);
    }
}
