<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\Contracts\AiTool;
use App\Models\User;

/**
 * Shared behaviour for tools that only look things up.
 */
abstract class ReadTool implements AiTool
{
    public function isReadOnly(): bool
    {
        return true;
    }

    public function summarise(User $user, array $arguments): string
    {
        return 'Look up '.str_replace('_', ' ', $this->definition()->name);
    }
}
