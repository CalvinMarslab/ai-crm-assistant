<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\Data\LlmMessage;
use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Ai\Exceptions\AssistantUnavailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Speaks the OpenAI chat-completions dialect, which most hosted and
 * self-hosted models now accept. Point base_url at whichever endpoint serves
 * the chosen model.
 */
class OpenAiCompatibleProvider implements LlmProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeout = 60,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '' && $this->model !== '';
    }

    public function name(): string
    {
        return $this->model;
    }

    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<int, ToolDefinition>  $tools
     */
    public function chat(array $messages, array $tools = []): LlmMessage
    {
        if (! $this->isConfigured()) {
            throw AssistantUnavailable::notConfigured();
        }

        $payload = [
            'model' => $this->model,
            'messages' => array_map($this->encodeMessage(...), $messages),
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(
                fn (ToolDefinition $tool) => ['type' => 'function', 'function' => $tool->toArray()],
                $tools,
            );
            $payload['tool_choice'] = 'auto';
        }

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->acceptJson()
            ->post(rtrim($this->baseUrl, '/').'/chat/completions', $payload);

        if ($response->failed()) {
            // The body can carry the customer question back verbatim, so it is
            // logged at the boundary and not surfaced to the caller.
            Log::warning('LLM request failed', [
                'status' => $response->status(),
                'model' => $this->model,
            ]);

            throw AssistantUnavailable::providerError($response->status());
        }

        return $this->decodeMessage($response->json('choices.0.message') ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeMessage(LlmMessage $message): array
    {
        $encoded = ['role' => $message->role];

        if ($message->content !== null) {
            $encoded['content'] = $message->content;
        }

        if ($message->hasToolCalls()) {
            $encoded['tool_calls'] = array_map(fn (array $call) => [
                'id' => $call['id'],
                'type' => 'function',
                'function' => [
                    'name' => $call['name'],
                    'arguments' => json_encode($call['arguments'] ?? [], JSON_THROW_ON_ERROR),
                ],
            ], $message->toolCalls);
        }

        if ($message->toolCallId !== null) {
            $encoded['tool_call_id'] = $message->toolCallId;
        }

        if ($message->name !== null) {
            $encoded['name'] = $message->name;
        }

        return $encoded;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function decodeMessage(array $raw): LlmMessage
    {
        $toolCalls = null;

        foreach ($raw['tool_calls'] ?? [] as $call) {
            // Arguments arrive as a JSON string and may be malformed; a broken
            // one becomes an empty argument list rather than an exception, so
            // the assistant can recover by asking again.
            $arguments = json_decode($call['function']['arguments'] ?? '{}', true);

            $toolCalls[] = [
                'id' => $call['id'] ?? uniqid('call_', true),
                'name' => $call['function']['name'] ?? '',
                'arguments' => is_array($arguments) ? $arguments : [],
            ];
        }

        return LlmMessage::assistant($raw['content'] ?? null, $toolCalls);
    }
}
