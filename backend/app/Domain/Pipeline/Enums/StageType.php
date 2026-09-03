<?php

namespace App\Domain\Pipeline\Enums;

enum StageType: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';
    case Hold = 'hold';

    public function isTerminal(): bool
    {
        return $this === self::Won || $this === self::Lost;
    }
}
