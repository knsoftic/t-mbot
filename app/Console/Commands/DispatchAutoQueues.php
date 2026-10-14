<?php

namespace App\Console\Commands;

use App\Services\AutoQueueService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('auto-queues:dispatch-due')]
#[Description('Post the next Content Library item of every Auto Queue whose posting time has come')]
class DispatchAutoQueues extends Command
{
    public function handle(AutoQueueService $queues): int
    {
        $count = $queues->dispatchDue();

        if ($count > 0) {
            $this->info("Queued {$count} Auto Queue post(s).");
        }

        return self::SUCCESS;
    }
}
