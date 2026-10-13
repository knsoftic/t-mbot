<?php

namespace App\Events;

use App\Models\TelegramBot;
use Illuminate\Foundation\Events\Dispatchable;

class BotConnectionFailed
{
    use Dispatchable;

    public function __construct(
        public TelegramBot $bot,
        public string $message,
    ) {}
}
