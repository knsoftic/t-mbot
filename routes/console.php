<?php

use App\Jobs\QueueHeartbeatJob;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler
|--------------------------------------------------------------------------
|
| A single cron entry runs `php artisan schedule:run` every minute. Campaign
| timing lives in the database; this scheduler only polls for due work.
|
*/

// Find due campaigns / due retries and queue them (10 s resolution).
Schedule::command('campaigns:dispatch-due')
    ->everyTenSeconds()
    ->withoutOverlapping(2)
    ->runInBackground();

// Auto Queues: post the next library item at each posting time.
Schedule::command('auto-queues:dispatch-due')
    ->everyTenSeconds()
    ->withoutOverlapping(2)
    ->runInBackground();

// Repair state after crashes: stale "processing" items, lost schedules.
Schedule::command('campaigns:recover')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Detect revoked bot tokens and alert administrators.
Schedule::command('bots:check')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground();

// Subscriptions: expiry reminders and the move back to the free plan.
Schedule::command('plans:expire')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground();

// Worker heartbeats for the System Health page.
Schedule::call(function () {
    foreach (config('telegram.queues') as $queue) {
        QueueHeartbeatJob::dispatch($queue);
    }
})->everyMinute()->name('queue-heartbeat');

// Housekeeping.
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('queue:prune-batches --hours=168')->daily();
