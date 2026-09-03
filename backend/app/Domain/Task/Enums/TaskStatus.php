<?php

namespace App\Domain\Task\Enums;

enum TaskStatus: string
{
    case ToDo = 'to_do';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function isClosed(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }

    /**
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return [self::ToDo->value, self::InProgress->value, self::Waiting->value];
    }
}
