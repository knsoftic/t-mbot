<?php

namespace App\Console\Commands;

use App\Services\RecoveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('campaigns:recover')]
#[Description('Recover items left in "processing" by a crash or restart, without duplicating posts')]
class RecoverCampaigns extends Command
{
    public function handle(RecoveryService $recovery): int
    {
        $stats = $recovery->recover();

        $this->table(['Stale items', 'Re-queued', 'Uncertain (not re-sent)', 'Schedules repaired'], [array_values($stats)]);

        return self::SUCCESS;
    }
}
