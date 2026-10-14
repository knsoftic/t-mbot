<?php

namespace App\Listeners;

use App\Enums\CampaignStatus;
use App\Events\CampaignStatusChanged;
use App\Notifications\AdminAlert;
use App\Services\AdminNotifier;
use Illuminate\Support\Facades\Log;

class NotifyCampaignStatusChange
{
    public function __construct(private readonly AdminNotifier $notifier) {}

    public function handle(CampaignStatusChanged $event): void
    {
        Log::channel('campaign')->info('Campaign status changed', [
            'campaign_id' => $event->campaign->id,
            'from' => $event->from->value,
            'to' => $event->to->value,
        ]);

        $startedFrom = [CampaignStatus::Draft, CampaignStatus::Cancelled, CampaignStatus::Completed, CampaignStatus::Failed];

        $alert = match (true) {
            $event->to->isActive() && in_array($event->from, $startedFrom, true) => AdminAlert::campaignStarted($event->campaign),
            $event->to === CampaignStatus::Completed => AdminAlert::campaignCompleted($event->campaign),
            $event->to === CampaignStatus::Failed => AdminAlert::campaignFailed($event->campaign),
            default => null,
        };

        if ($alert) {
            $this->notifier->send($alert, $event->campaign->user_id);
        }
    }
}
