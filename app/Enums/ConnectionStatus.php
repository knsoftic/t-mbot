<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    use Concerns;

    case Unknown = 'unknown';
    case Testing = 'testing';
    case Connected = 'connected';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not tested',
            self::Testing => 'Testing…',
            self::Connected => 'Connected',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unknown => 'slate',
            self::Testing => 'blue',
            self::Connected => 'green',
            self::Failed => 'red',
        };
    }
}
