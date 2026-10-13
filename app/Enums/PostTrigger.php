<?php

namespace App\Enums;

enum PostTrigger: string
{
    use Concerns;

    case Schedule = 'schedule';
    case Manual = 'manual';
    case Retry = 'retry';
}
