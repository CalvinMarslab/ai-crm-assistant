<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\Contracts\LlmProvider;
use App\Domain\Ai\Data\LlmMessage;
use App\Domain\Ai\Data\ToolDefinition;
use App\Domain\Ai\Exceptions\AssistantUnavailable;

/**
 * Used when no provider is configured. It fails loudly rather than pretending
 * to answer, so an unconfigured deployment cannot quietly invent replies.
 *
 * The daily brief and risk detection do not go through a provider at all, so
 * they keep working with this in place.
 */
class NullProvider implements LlmProvider
{
    public function chat(array $messages, array $tools = []): LlmMessage
    {
        throw AssistantUnavailable::notConfigured();
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'none';
    }
}
