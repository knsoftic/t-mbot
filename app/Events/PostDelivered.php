<?php

namespace App\Events;

use App\Models\PostingLog;
use Illuminate\Foundation\Events\Dispatchable;

class PostDelivered
{
    use Dispatchable;

    public function __construct(public PostingLog $log) {}
}
