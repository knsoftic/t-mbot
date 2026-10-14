<?php

namespace App\Listeners;

use App\Enums\PostingStatus;
use App\Events\PostFailed;
use App\Models\Campaign;
use App\Models\PostingLog;
use App\Notifications\AdminAlert;
use App\Services\AdminNotifier;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

/**
 * Alerts admins when the last N posting attempts of a campaign all failed.
 * At most one alert per campaign per hour.
 */
class NotifyRepeatedFailures
{
    public function __construct(
        private readonly AdminNotifier $notifier,
        private readonly SettingsService $settings,
    ) {}

    public function handle(PostFailed $event): void
    {
        $campaignId = $event->log->campaign_id;
        $threshold = max(2, (int) $this->settings->get('notifications.failure_threshold'));

        if (! $campaignId) {
            return;
        }

        $recent = PostingLog::query()
            ->where('campaign_id', $campaignId)
            ->whereIn('status', [PostingStatus::Sent, PostingStatus::Failed])
            ->latest('id')
            ->limit($threshold)
            ->pluck('status');

        if ($recent->count() < $threshold || $recent->contains(PostingStatus::Sent)) {
            return;
        }

        if (! Cache::add("alerts:repeated-failures:{$campaignId}", true, now()->addHour())) {
            return;
        }

        if ($campaign = Campaign::withTrashed()->find($campaignId)) {
            $this->notifier->send(AdminAlert::repeatedFailures($campaign, $threshold), $campaign->user_id);
        }
    }
}
