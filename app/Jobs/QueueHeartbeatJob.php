<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Dispatched every minute by the scheduler; proves a worker is consuming
 * the given queue (read by the System Health page).
 */
class QueueHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $queueName)
    {
        $this->onQueue($queueName);
    }

    public function handle(): void
    {
        Cache::put('health:queue:'.$this->queueName, now()->getTimestamp(), now()->addHours(2));
    }
}
