<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('plans:expire')]
#[Description('Move accounts with an expired paid plan back to the free plan and remind accounts whose plan ends soon')]
class ExpirePlans extends Command
{
    public function handle(BillingService $billing): int
    {
        $reminded = $billing->sendExpiryReminders();
        $expired = $billing->expireDuePlans();

        if ($reminded + $expired > 0) {
            $this->info("Reminded {$reminded} account(s), expired {$expired} plan(s).");
        }

        return self::SUCCESS;
    }
}
