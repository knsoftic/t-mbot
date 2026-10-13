<?php

namespace App\Enums;

enum ParseMode: string
{
    use Concerns;

    case Html = 'HTML';
    case MarkdownV2 = 'MarkdownV2';

    public function label(): string
    {
        return $this->value;
    }
}
