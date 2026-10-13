<?php

namespace App\Events;

use App\Models\PostingLog;
use App\Services\Telegram\TelegramErrorType;
use Illuminate\Foundation\Events\Dispatchable;

class PostFailed
{
    use Dispatchable;

    public function __construct(
        public PostingLog $log,
        public bool $willRetry,
        public TelegramErrorType $type,
    ) {}
}
