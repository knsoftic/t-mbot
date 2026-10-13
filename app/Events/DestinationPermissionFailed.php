<?php

namespace App\Events;

use App\Models\TelegramDestination;
use Illuminate\Foundation\Events\Dispatchable;

class DestinationPermissionFailed
{
    use Dispatchable;

    public function __construct(
        public TelegramDestination $destination,
        public string $message,
    ) {}
}
