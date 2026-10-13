<?php

namespace App\Enums;

enum PostingOrder: string
{
    use Concerns;

    case Sequential = 'sequential';
    case Random = 'random';
}
