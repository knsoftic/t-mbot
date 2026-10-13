<?php

namespace App\Jobs;

use App\Enums\PostTrigger;
use App\Services\CampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Re-attempts a failed item once its retry delay (or Telegram's retry_after)
 * has passed. If this job is lost, the scheduler picks up the due retry.
 */
class RetryTelegramPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $campaignId,
        public int $itemId,
    ) {
        $this->onQueue(config('telegram.queues.campaigns'));
    }

    public function handle(CampaignService $campaigns): void
    {
        $outcome = $campaigns->process($this->campaignId, PostTrigger::Retry, $this->itemId);

        Log::channel('queue')->debug('RetryTelegramPostJob finished', [
            'campaign_id' => $this->campaignId,
            'item_id' => $this->itemId,
            'outcome' => $outcome,
        ]);
    }
}
