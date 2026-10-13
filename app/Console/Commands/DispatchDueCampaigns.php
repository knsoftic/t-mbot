<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Enums\ItemStatus;
use App\Enums\PostTrigger;
use App\Jobs\ProcessCampaignJob;
use App\Models\Campaign;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The single entry point the scheduler uses to start posting: finds every
 * campaign that is due (or has a due retry) and queues a ProcessCampaignJob.
 * No per-campaign cron entries are ever created.
 */
#[Signature('campaigns:dispatch-due')]
#[Description('Queue processing jobs for campaigns whose next post is due')]
class DispatchDueCampaigns extends Command
{
    public function handle(): int
    {
        $now = now();
        $count = 0;

        Campaign::query()
            ->whereIn('status', CampaignStatus::active())
            ->where(function (Builder $q) use ($now) {
                $q->where(function (Builder $due) use ($now) {
                    $due->where('next_run_at', '<=', $now)
                        ->whereDoesntHave('campaignItems', fn (Builder $i) => $i
                            ->where('status', ItemStatus::Processing)
                            ->orWhere(fn (Builder $w) => $w->where('status', ItemStatus::Pending)->whereNotNull('next_attempt_at')));
                })->orWhereHas('campaignItems', fn (Builder $i) => $i
                    ->where('status', ItemStatus::Pending)
                    ->where('next_attempt_at', '<=', $now->copy()->subSeconds(30)));
            })
            ->orderBy('next_run_at')
            ->limit(500)
            ->pluck('id')
            ->each(function (int $id) use (&$count) {
                ProcessCampaignJob::dispatch($id, PostTrigger::Schedule);
                $count++;
            });

        Cache::put('health:scheduler', $now->getTimestamp(), now()->addHours(2));

        if ($count > 0) {
            $this->info("Queued {$count} campaign(s).");
        }

        return self::SUCCESS;
    }
}
