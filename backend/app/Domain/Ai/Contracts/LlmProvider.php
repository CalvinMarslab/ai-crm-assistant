<?php

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\Data\LlmMessage;
use App\Domain\Ai\Data\ToolDefinition;

/**
 * The seam between the assistant and whichever model serves it.
 *
 * Kept deliberately small: send a conversation and a list of tools, get one
 * reply back, which is either prose or a request to call tools. Everything
 * else — which tools exist, whether the user may run them, whether a write
 * needs confirming — is decided on this side of the boundary, because the
 * model is untrusted input, not an authority.
 */
interface LlmProvider
{
    /**
     * @param  array<int, LlmMessage>  $messages
     * @param  array<int, ToolDefinition>  $tools
     */
    public function chat(array $messages, array $tools = []): LlmMessage;

    /** Whether the provider has everything it needs to be called. */
    public function isConfigured(): bool;

    /** Shown to the user when the assistant is unavailable. */
    public function name(): string;
}
