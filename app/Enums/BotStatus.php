<?php

namespace App\Enums;

enum BotStatus: string
{
    use Concerns;

    case Active = 'active';
    case Disabled = 'disabled';

    public function color(): string
    {
        return $this === self::Active ? 'green' : 'slate';
    }
}
