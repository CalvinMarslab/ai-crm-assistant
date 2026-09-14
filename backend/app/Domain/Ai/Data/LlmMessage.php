<?php

namespace App\Domain\Ai\Data;

/**
 * One turn in a conversation, in the shape every provider adapter translates
 * to and from. Business code never sees a provider's own wire format.
 */
final readonly class LlmMessage
{
    /**
     * @param  array<int, array<string, mixed>>|null  $toolCalls
     */
    public function __construct(
        public string $role,
        public ?string $content = null,
        public ?array $toolCalls = null,
        public ?string $toolCallId = null,
        public ?string $name = null,
    ) {}

    public static function system(string $content): self
    {
        return new self('system', $content);
    }

    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $toolCalls
     */
    public static function assistant(?string $content, ?array $toolCalls = null): self
    {
        return new self('assistant', $content, $toolCalls);
    }

    public static function toolResult(string $toolCallId, string $name, string $content): self
    {
        return new self('tool', $content, null, $toolCallId, $name);
    }

    public function hasToolCalls(): bool
    {
        return ! empty($this->toolCalls);
    }
}
