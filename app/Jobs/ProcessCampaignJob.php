<?php

namespace App\Jobs;

use App\Enums\PostTrigger;
use App\Services\CampaignService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Picks the next item of one campaign and queues its delivery.
 *
 * Dispatched by the scheduler (campaigns:dispatch-due) and by Run Now.
 * Uniqueness keeps the queue from piling up identical jobs; correctness
 * does not depend on it (CampaignService::process locks the campaign row).
 */
class ProcessCampaignJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    public function __construct(
        public int $campaignId,
        public PostTrigger $trigger = PostTrigger::Schedule,
    ) {
        $this->onQueue(config('telegram.queues.campaigns'));
    }

    public function uniqueId(): string
    {
        return $this->campaignId.':'.$this->trigger->value;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15];
    }

    public function handle(CampaignService $campaigns): void
    {
        $outcome = $campaigns->process($this->campaignId, $this->trigger);

        Log::channel('queue')->debug('ProcessCampaignJob finished', [
            'campaign_id' => $this->campaignId,
            'trigger' => $this->trigger->value,
            'outcome' => $outcome,
        ]);
    }
}
