<?php

namespace App\Domain\Ai\Exceptions;

use RuntimeException;

class AssistantUnavailable extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('The AI assistant is not configured. Add an LLM provider in the environment settings.');
    }

    public static function providerError(int $status): self
    {
        return new self("The AI provider returned an error ({$status}). Please try again.");
    }
}
