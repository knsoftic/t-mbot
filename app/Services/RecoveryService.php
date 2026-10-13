<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\ItemStatus;
use App\Enums\PostingStatus;
use App\Enums\TriggerType;
use App\Jobs\SendTelegramContentJob;
use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\PostingLog;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramErrorType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Brings campaigns back to a consistent state after crashes and restarts.
 *
 * Rule of thumb: if we never called Telegram for an attempt it is safe to
 * redo it; if we did call Telegram but never recorded the answer, the post
 * may already be live, so it is marked failed (never re-sent automatically)
 * and the administrator decides.
 */
class RecoveryService
{
    public const UNCERTAIN_MESSAGE = 'Delivery was interrupted after the request reached Telegram, so the post may or may not have been published. '
        .'It was not re-sent automatically to avoid a duplicate. Check the chat and use Retry on the item if needed.';

    public function __construct(
        private readonly PostingService $posting,
        private readonly ScheduleCalculator $calculator,
    ) {}

    /**
     * @return array{stale_items: int, requeued: int, uncertain: int, rescheduled: int}
     */
    public function recover(): array
    {
        $stats = ['stale_items' => 0, 'requeued' => 0, 'uncertain' => 0, 'rescheduled' => 0];
        $threshold = CarbonImmutable::now()->subMinutes((int) config('telegram.stale_processing_minutes', 10));

        CampaignItem::query()
            ->where('status', ItemStatus::Processing)
            ->where(fn ($q) => $q->whereNull('processing_started_at')->orWhere('processing_started_at', '<', $threshold))
            ->orderBy('id')
            ->chunkById(100, function ($items) use (&$stats) {
                foreach ($items as $item) {
                    $stats['stale_items']++;
                    $result = $item->current_log_id ? $this->recoverLog((int) $item->current_log_id) : $this->releaseOrphan($item);
                    $stats[$result] = ($stats[$result] ?? 0) + 1;
                }
            });

        $stats['rescheduled'] = $this->repairSchedules();

        if (array_sum($stats) > 0) {
            Log::channel('campaign')->info('Recovery run', $stats);
        }

        return array_intersect_key($stats, array_flip(['stale_items', 'requeued', 'uncertain', 'rescheduled']));
    }

    /**
     * Recover one "processing" attempt.
     *
     * @return string requeued|uncertain|failed|noop
     */
    public function recoverLog(int $logId, bool $fromFailedJob = false): string
    {
        $log = PostingLog::query()->find($logId);

        if (! $log || $log->status !== PostingStatus::Processing) {
            return 'noop';
        }

        $item = $log->campaignItem;

        if (! $item || $item->status !== ItemStatus::Processing || (int) $item->current_log_id !== $log->id) {
            $log->forceFill(['status' => PostingStatus::Failed, 'error_message' => 'Attempt superseded by a newer attempt.'])->save();

            return 'noop';
        }

        if ($log->api_called_at === null) {
            if ($fromFailedJob) {
                // The job died before talking to Telegram: a normal, retryable failure.
                $this->posting->recordFailure($log, $item, new TelegramApiException(
                    TelegramErrorType::Unknown,
                    'The delivery job failed before contacting Telegram.',
                ));

                return 'failed';
            }

            // The job never ran (lost on restart): queue it again. Uniqueness and the
            // api_called_at guard make this safe even if the original job still exists.
            dispatch(SendTelegramContentJob::forItem($item, $log));

            return 'requeued';
        }

        $this->posting->recordFailure(
            $log,
            $item,
            new TelegramApiException(TelegramErrorType::Unknown, self::UNCERTAIN_MESSAGE),
            permanent: true,
            allowRetry: false,
        );

        return 'uncertain';
    }

    private function releaseOrphan(CampaignItem $item): string
    {
        CampaignItem::query()->whereKey($item->id)->where('status', ItemStatus::Processing)->update([
            'status' => ItemStatus::Pending,
            'processing_started_at' => null,
            'updated_at' => CarbonImmutable::now(),
        ]);

        return 'requeued';
    }

    /**
     * Active campaigns that lost their next run time (e.g. crash mid-update).
     */
    private function repairSchedules(): int
    {
        $count = 0;

        Campaign::query()
            ->whereIn('status', CampaignStatus::active())
            ->whereNull('next_run_at')
            ->where('trigger_type', '!=', TriggerType::Manual)
            ->whereHas('campaignItems', fn ($q) => $q->where('status', ItemStatus::Pending))
            ->each(function (Campaign $campaign) use (&$count) {
                DB::transaction(function () use ($campaign, &$count) {
                    $campaign = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->first();

                    if ($campaign && $campaign->next_run_at === null) {
                        $campaign->next_run_at = $this->calculator->nextRun($campaign, CarbonImmutable::now()->subSecond());

                        if ($campaign->next_run_at !== null) {
                            $campaign->save();
                            $count++;
                        }
                    }
                });
            });

        return $count;
    }
}
