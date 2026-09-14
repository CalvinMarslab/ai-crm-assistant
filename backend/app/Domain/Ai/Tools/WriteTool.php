<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\Contracts\AiTool;

/**
 * Shared behaviour for tools that change something.
 *
 * These are never executed during a conversation turn. The assistant's request
 * becomes a pending action the user confirms, and only then does execute()
 * run — through the same domain services and policies a human would go
 * through, so the write is audited identically.
 */
abstract class WriteTool implements AiTool
{
    public function isReadOnly(): bool
    {
        return false;
    }
}
