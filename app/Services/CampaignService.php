<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\ItemStatus;
use App\Enums\PostingOrder;
use App\Enums\PostingStatus;
use App\Enums\PostTrigger;
use App\Enums\RepeatMode;
use App\Enums\TriggerType;
use App\Events\CampaignStatusChanged;
use App\Jobs\ProcessCampaignJob;
use App\Jobs\SendTelegramContentJob;
use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\PostingLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Campaign lifecycle and the "post the next item" step.
 *
 * Concurrency model: every state change of a campaign or its items happens
 * inside a transaction that first takes a row lock on the campaign
 * (SELECT ... FOR UPDATE). Only one item per campaign can be "processing"
 * at a time, items move pending -> processing with an atomic update, and
 * PostingService guards the actual API call with a second atomic flag, so
 * the same item can never be sent twice by concurrent workers.
 */
class CampaignService
{
    public function __construct(
        private readonly ScheduleCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Problems that prevent the campaign from posting. Empty when ready.
     *
     * @return list<string>
     */
    public function readinessProblems(Campaign $campaign): array
    {
        $problems = [];
        $campaign->loadMissing(['bot', 'destination', 'testDestination', 'schedules']);

        if (! $campaign->bot || $campaign->bot->trashed()) {
            $problems[] = 'The selected bot no longer exists.';
        } elseif (! $campaign->bot->isActive()) {
            $problems[] = "Bot \"{$campaign->bot->name}\" is disabled.";
        }

        $destination = $campaign->destination;

        if (! $destination || $destination->trashed()) {
            $problems[] = 'The selected destination no longer exists.';
        } elseif (! $destination->isActive()) {
            $problems[] = "Destination \"{$destination->name}\" is inactive.";
        }

        if ($campaign->test_mode) {
            $test = $campaign->testDestination;

            if (! $test || $test->trashed()) {
                $problems[] = 'Test mode is on but no test chat is selected.';
            } elseif ($campaign->bot && $test->bot_id !== $campaign->bot_id) {
                $problems[] = 'The test chat must be connected to the same bot as the campaign.';
            }
        }

        if ($destination && $campaign->bot && $destination->bot_id !== $campaign->bot_id) {
            $problems[] = 'The destination is connected to a different bot.';
        }

        $items = $campaign->items()->with('content')->get();

        if ($items->isEmpty()) {
            $problems[] = 'Add at least one content item to the sequence.';
        }

        foreach ($items as $item) {
            if (! $item->content || $item->content->trashed()) {
                $problems[] = "Item #{$item->sort_order} refers to deleted content.";
            }
        }

        match ($campaign->trigger_type) {
            TriggerType::Interval => (int) $campaign->interval_seconds < 60
                ? $problems[] = 'The interval must be at least 1 minute.' : null,
            TriggerType::FixedTime, TriggerType::Daily, TriggerType::Weekly => $campaign->schedules->isEmpty()
                ? $problems[] = 'Add at least one posting time.' : null,
            TriggerType::Scheduled => $items->contains(fn (CampaignItem $i) => $i->scheduled_at === null)
                ? $problems[] = 'Every item needs a publish date & time for the Scheduled trigger.' : null,
            default => null,
        };

        if ($campaign->end_at && $campaign->start_at && $campaign->end_at->lessThanOrEqualTo($campaign->start_at)) {
            $problems[] = 'The end date must be after the start date.';
        }

        if ($campaign->end_at && $campaign->end_at->isPast()) {
            $problems[] = 'The end date is in the past.';
        }

        return $problems;
    }

    private function assertReady(Campaign $campaign): void
    {
        if ($problems = $this->readinessProblems($campaign)) {
            throw ValidationException::withMessages(['campaign' => $problems]);
        }
    }

    // ------------------------------------------------------------------
    // Lifecycle actions
    // ------------------------------------------------------------------

    public function activate(Campaign $campaign, ?User $user = null): Campaign
    {
        $this->assertReady($campaign);

        $campaign = DB::transaction(function () use ($campaign) {
            $campaign = $this->lock($campaign);

            if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Cancelled, CampaignStatus::Completed, CampaignStatus::Failed], true)) {
                throw ValidationException::withMessages(['campaign' => "A {$campaign->status->label()} campaign cannot be activated."]);
            }

            $now = CarbonImmutable::now();

            if ($campaign->status !== CampaignStatus::Draft) {
                $this->resetItems($campaign, $campaign->cycle + 1);
                $campaign->cycle++;
            }

            $campaign->schedule_anchor_at = $campaign->start_at && $campaign->start_at->greaterThan($now) ? $campaign->start_at : $now;
            $campaign->cycle_started_at = $campaign->schedule_anchor_at;
            $campaign->next_run_at = $this->calculator->firstRun($campaign, $now);
            $campaign->activated_at = $now;
            $campaign->completed_at = null;
            $campaign->paused_at = null;
            $campaign->last_error = null;
            $from = $campaign->status;
            $campaign->status = $this->startingStatus($campaign, $now);
            $campaign->save();

            $this->statusChanged($campaign, $from);

            return $campaign;
        });

        $this->audit->log('campaign.started', $campaign, "Campaign \"{$campaign->name}\" activated", ['next_run_at' => $campaign->next_run_at], $user);

        return $campaign;
    }

    public function pause(Campaign $campaign, ?User $user = null): Campaign
    {
        $campaign = DB::transaction(function () use ($campaign) {
            $campaign = $this->lock($campaign);
            $this->assertStatus($campaign, [CampaignStatus::Scheduled, CampaignStatus::Running], 'paused');

            $from = $campaign->status;
            $campaign->status = CampaignStatus::Paused;
            $campaign->paused_at = CarbonImmutable::now();
            $campaign->save();
            $this->statusChanged($campaign, $from);

            return $campaign;
        });

        $this->audit->log('campaign.paused', $campaign, "Campaign \"{$campaign->name}\" paused", [], $user);

        return $campaign;
    }

    /**
     * Continue from the next pending item; never restarts the sequence.
     */
    public function resume(Campaign $campaign, ?User $user = null): Campaign
    {
        $this->assertReady($campaign);

        $campaign = DB::transaction(function () use ($campaign) {
            $campaign = $this->lock($campaign);
            $this->assertStatus($campaign, [CampaignStatus::Paused, CampaignStatus::Failed], 'resumed');

            $now = CarbonImmutable::now();

            // Retries that were waiting when the campaign was paused become due at the next slot.
            $campaign->items()->where('status', ItemStatus::Pending)->whereNotNull('next_attempt_at')
                ->update(['next_attempt_at' => null]);

            $from = $campaign->status;
            $campaign->paused_at = null;
            $campaign->last_error = null;

            if ($campaign->trigger_type === TriggerType::Interval && $campaign->schedule_anchor_at === null) {
                $campaign->schedule_anchor_at = $now;
            }

            $campaign->next_run_at = $this->resumeRunTime($campaign, $now);
            $campaign->status = $this->startingStatus($campaign, $now);
            $campaign->save();
            $this->statusChanged($campaign, $from);

            if (! $this->hasOpenItems($campaign)) {
                $this->completeCycle($campaign, $now);
            }

            return $campaign;
        });

        $this->audit->log('campaign.resumed', $campaign, "Campaign \"{$campaign->name}\" resumed", ['next_run_at' => $campaign->next_run_at], $user);

        return $campaign;
    }

    public function stop(Campaign $campaign, ?User $user = null): Campaign
    {
        $campaign = DB::transaction(function () use ($campaign) {
            $campaign = $this->lock($campaign);
            $this->assertStatus($campaign, [CampaignStatus::Scheduled, CampaignStatus::Running, CampaignStatus::Paused, CampaignStatus::Failed], 'stopped');

            $from = $campaign->status;
            $campaign->status = CampaignStatus::Cancelled;
            $campaign->next_run_at = null;
            $campaign->save();

            // Items waiting for a retry are released; an in-flight item finishes normally.
            $campaign->items()->where('status', ItemStatus::Pending)->whereNotNull('next_attempt_at')
                ->update(['next_attempt_at' => null]);

            $this->statusChanged($campaign, $from);

            return $campaign;
        });

        $this->audit->log('campaign.stopped', $campaign, "Campaign \"{$campaign->name}\" stopped", [], $user);

        return $campaign;
    }

    /**
     * Start over from item #1 with a new execution cycle. Posting logs are kept.
     */
    public function restart(Campaign $campaign, ?User $user = null): Campaign
    {
        $this->assertReady($campaign);

        $campaign = DB::transaction(function () use ($campaign) {
            $campaign = $this->lock($campaign);

            if ($campaign->status === CampaignStatus::Draft) {
                throw ValidationException::withMessages(['campaign' => 'Activate the campaign instead of restarting a draft.']);
            }

            if ($campaign->campaignItems()->where('status', ItemStatus::Processing)->exists()) {
                throw ValidationException::withMessages(['campaign' => 'An item is being delivered right now. Try again in a few seconds.']);
            }

            $now = CarbonImmutable::now();
            $from = $campaign->status;

            $this->resetItems($campaign, $campaign->cycle + 1);
            $campaign->cycle++;
            $campaign->cycles_completed = 0;
            $campaign->schedule_anchor_at = $campaign->start_at && $campaign->start_at->greaterThan($now) ? $campaign->start_at : $now;
            $campaign->cycle_started_at = $campaign->schedule_anchor_at;
            $campaign->next_run_at = $this->calculator->firstRun($campaign, $now);
            $campaign->completed_at = null;
            $campaign->paused_at = null;
            $campaign->last_error = null;
            $campaign->status = $this->startingStatus($campaign, $now);
            $campaign->save();
            $this->statusChanged($campaign, $from);

            return $campaign;
        });

        $this->audit->log('campaign.restarted', $campaign, "Campaign \"{$campaign->name}\" restarted from item #1", ['cycle' => $campaign->cycle], $user);

        return $campaign;
    }

    /**
     * Queue the next pending item immediately, regardless of the schedule.
     */
    public function runNow(Campaign $campaign, ?User $user = null): void
    {
        $this->assertReady($campaign);
        $campaign->refresh();

        if (! $campaign->status->allowsManualRun()) {
            throw ValidationException::withMessages(['campaign' => "Run Now is not available for a {$campaign->status->label()} campaign. ".($campaign->status === CampaignStatus::Draft ? 'Activate it first.' : 'Restart it first.')]);
        }

        if ($campaign->campaignItems()->where('status', ItemStatus::Processing)->exists()) {
            throw ValidationException::withMessages(['campaign' => 'An item is being delivered right now. Wait for it to finish.']);
        }

        if (! $campaign->campaignItems()->where('status', ItemStatus::Pending)->exists() && $campaign->repeat_mode === RepeatMode::None) {
            throw ValidationException::withMessages(['campaign' => 'There are no pending items left. Restart the campaign to post again.']);
        }

        ProcessCampaignJob::dispatch($campaign->id, PostTrigger::Manual);

        $this->audit->log('campaign.run_now', $campaign, "Run Now triggered for \"{$campaign->name}\"", [], $user);
    }

    public function skipItem(CampaignItem $item, ?User $user = null): void
    {
        DB::transaction(function () use ($item) {
            $campaign = $this->lock($item->campaign);
            $item = CampaignItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($item->status !== ItemStatus::Pending && $item->status !== ItemStatus::Failed) {
                throw ValidationException::withMessages(['item' => "Only pending or failed items can be skipped (this item is {$item->status->value})."]);
            }

            $item->status = ItemStatus::Skipped;
            $item->next_attempt_at = null;
            $item->error_message = null;
            $item->save();

            PostingLog::create([
                'campaign_id' => $campaign->id,
                'campaign_item_id' => $item->id,
                'bot_id' => $campaign->bot_id,
                'destination_id' => $campaign->destination_id,
                'content_id' => $item->content_id,
                'status' => PostingStatus::Skipped,
                'trigger' => PostTrigger::Manual,
                'is_test' => $campaign->test_mode,
                'cycle' => $campaign->cycle,
                'attempts' => $item->attempts + 1,
                'error_message' => 'Skipped by administrator',
            ]);
            $item->increment('attempts');

            if ($campaign->status->isActive() && ! $this->hasOpenItems($campaign)) {
                $this->completeCycle($campaign, CarbonImmutable::now());
            }
        });

        $this->audit->log('campaign.item_skipped', $item->campaign, "Item #{$item->sort_order} skipped", ['item_id' => $item->id], $user);
    }

    /**
     * Put a failed/skipped item back in the queue; it is posted at the next run (or Run Now).
     */
    public function retryItem(CampaignItem $item, ?User $user = null): void
    {
        DB::transaction(function () use ($item) {
            $this->lock($item->campaign);
            $item = CampaignItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if (! in_array($item->status, [ItemStatus::Failed, ItemStatus::Skipped], true)) {
                throw ValidationException::withMessages(['item' => 'Only failed or skipped items can be retried.']);
            }

            $item->status = ItemStatus::Pending;
            $item->failed_attempts = 0;
            $item->next_attempt_at = null;
            $item->error_message = null;
            $item->save();
        });

        $campaign = $item->campaign->refresh();

        // A finished campaign has no next run; give the retried item one.
        if (in_array($campaign->status, [CampaignStatus::Completed], true)) {
            DB::transaction(function () use ($campaign) {
                $campaign = $this->lock($campaign);
                $from = $campaign->status;
                $campaign->status = CampaignStatus::Running;
                $campaign->completed_at = null;
                $campaign->cycles_completed = max(0, $campaign->cycles_completed - 1);
                $campaign->next_run_at = $this->resumeRunTime($campaign, CarbonImmutable::now());
                $campaign->save();
                $this->statusChanged($campaign, $from);
            });
        }

        $this->audit->log('campaign.item_retried', $campaign, "Item #{$item->sort_order} queued for retry", ['item_id' => $item->id], $user);
    }

    // ------------------------------------------------------------------
    // The posting step
    // ------------------------------------------------------------------

    /**
     * Claim the next item of a campaign and queue its delivery.
     *
     * Returns a short outcome code (useful for logs and tests).
     */
    public function process(int $campaignId, PostTrigger $trigger, ?int $retryItemId = null): string
    {
        return DB::transaction(function () use ($campaignId, $trigger, $retryItemId) {
            $campaign = Campaign::query()->whereKey($campaignId)->lockForUpdate()->first();

            if (! $campaign) {
                return 'missing';
            }

            $now = CarbonImmutable::now();
            $allowed = $trigger === PostTrigger::Manual ? $campaign->status->allowsManualRun() : $campaign->status->isActive();

            if (! $allowed) {
                return 'inactive';
            }

            // Strict one-by-one posting: never start an item while another is in flight.
            if ($campaign->campaignItems()->where('status', ItemStatus::Processing)->exists()) {
                return 'busy';
            }

            $waiting = $campaign->items()->where('status', ItemStatus::Pending)->whereNotNull('next_attempt_at')
                ->reorder('next_attempt_at')->first();

            if ($trigger === PostTrigger::Retry) {
                $item = $waiting && $waiting->id === $retryItemId ? $waiting : null;

                // Jobs can run a moment early; allow a few seconds of slack.
                if (! $item || $item->next_attempt_at->greaterThan($now->addSeconds(5))) {
                    return 'not_due';
                }
            } elseif ($waiting !== null) {
                if ($trigger === PostTrigger::Schedule && $waiting->next_attempt_at->greaterThan($now)) {
                    return 'waiting_for_retry';
                }

                // A due retry (missed job) or a manual run takes over the waiting item.
                $item = $waiting;
                $trigger = $trigger === PostTrigger::Schedule ? PostTrigger::Retry : $trigger;
            } else {
                if ($trigger === PostTrigger::Schedule) {
                    if ($campaign->next_run_at === null || $campaign->next_run_at->greaterThan($now)) {
                        return 'not_due';
                    }

                    if ($campaign->end_at !== null && $now->greaterThan($campaign->end_at)) {
                        $this->finish($campaign);

                        return 'ended';
                    }
                }

                $item = $this->pickNextItem($campaign);

                if ($item === null) {
                    if (! $this->completeCycle($campaign, $now)) {
                        return 'completed';
                    }

                    $campaign->refresh();

                    if ($trigger === PostTrigger::Schedule && ($campaign->next_run_at === null || $campaign->next_run_at->greaterThan($now))) {
                        return 'cycle_restarted';
                    }

                    $item = $this->pickNextItem($campaign);

                    if ($item === null) {
                        return 'empty';
                    }
                }
            }

            $log = $this->claim($campaign, $item, $trigger, $now);
            $this->advanceSchedule($campaign, $trigger, $now);

            dispatch(SendTelegramContentJob::forItem($item, $log)->afterCommit());

            Log::channel('campaign')->info('Item claimed for delivery', [
                'campaign_id' => $campaign->id,
                'item_id' => $item->id,
                'log_id' => $log->id,
                'trigger' => $trigger->value,
                'attempt' => $log->attempts,
            ]);

            return 'dispatched';
        });
    }

    /**
     * Called (inside a transaction) when an item reaches a final state.
     */
    public function afterItemFinished(Campaign $campaign): void
    {
        $campaign = $this->lock($campaign);

        if ($campaign->status->isActive() && ! $this->hasOpenItems($campaign)) {
            $this->completeCycle($campaign, CarbonImmutable::now());
        }
    }

    /**
     * Mark a campaign failed (stop-on-failure behaviour, unusable bot, ...).
     */
    public function fail(Campaign $campaign, string $reason): void
    {
        $campaign = $this->lock($campaign);

        if ($campaign->status === CampaignStatus::Failed) {
            return;
        }

        $from = $campaign->status;
        $campaign->status = CampaignStatus::Failed;
        $campaign->last_error = mb_substr($reason, 0, 250);
        $campaign->next_run_at = null;
        $campaign->save();
        $this->statusChanged($campaign, $from);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function claim(Campaign $campaign, CampaignItem $item, PostTrigger $trigger, CarbonImmutable $now): PostingLog
    {
        $retryDueAt = $item->next_attempt_at;

        // Atomic pending -> processing transition; the campaign lock makes it uncontended,
        // the WHERE clause makes it safe even without the lock.
        $updated = CampaignItem::query()
            ->whereKey($item->id)
            ->where('status', ItemStatus::Pending)
            ->update([
                'status' => ItemStatus::Processing,
                'attempts' => DB::raw('attempts + 1'),
                'cycle' => $campaign->cycle,
                'processing_started_at' => $now,
                'next_attempt_at' => null,
                'updated_at' => $now,
            ]);

        if ($updated !== 1) {
            throw new \RuntimeException("Campaign item {$item->id} could not be claimed.");
        }

        $item->refresh();
        $destination = $campaign->deliveryDestination();

        $log = PostingLog::create([
            'campaign_id' => $campaign->id,
            'campaign_item_id' => $item->id,
            'bot_id' => $campaign->bot_id,
            'destination_id' => $destination?->id,
            'content_id' => $item->content_id,
            'status' => PostingStatus::Processing,
            'trigger' => $trigger,
            'is_test' => $campaign->test_mode,
            'chat_id' => $destination?->apiChatId(),
            'cycle' => $campaign->cycle,
            'attempts' => $item->attempts,
            'scheduled_at' => match (true) {
                $trigger === PostTrigger::Retry => $retryDueAt ?? $now,
                $trigger === PostTrigger::Schedule => $campaign->next_run_at ?? $now,
                default => $now,
            },
        ]);

        $item->forceFill(['current_log_id' => $log->id])->save();

        return $log;
    }

    private function advanceSchedule(Campaign $campaign, PostTrigger $trigger, CarbonImmutable $now): void
    {
        $from = $campaign->status;
        $campaign->last_run_at = $now;

        if ($campaign->status === CampaignStatus::Scheduled && $trigger !== PostTrigger::Manual) {
            $campaign->status = CampaignStatus::Running;
        }

        // Retries keep the existing slot; paused/scheduled campaigns keep their plan.
        if ($trigger !== PostTrigger::Retry && $campaign->status === CampaignStatus::Running) {
            if ($trigger === PostTrigger::Manual && $campaign->trigger_type === TriggerType::Interval) {
                // "Every 30 minutes" means 30 minutes after the manual post as well.
                $campaign->schedule_anchor_at = $now;
            }

            $campaign->next_run_at = $this->calculator->nextRun($campaign, $now);
        }

        $campaign->save();

        if ($from !== $campaign->status) {
            $this->statusChanged($campaign, $from);
        }
    }

    private function pickNextItem(Campaign $campaign): ?CampaignItem
    {
        $pending = $campaign->items()->where('status', ItemStatus::Pending);

        if ($campaign->posting_order === PostingOrder::Random && $campaign->trigger_type !== TriggerType::Scheduled) {
            $lastContentId = $campaign->campaignItems()->whereNotNull('sent_at')->latest('sent_at')->value('content_id');
            $candidates = (clone $pending)->get();

            if ($candidates->isEmpty()) {
                return null;
            }

            // Avoid posting the same content twice in a row when another option exists.
            $pool = $candidates->count() > 1 ? $candidates->reject(fn (CampaignItem $i) => $i->content_id === $lastContentId) : $candidates;

            return ($pool->isEmpty() ? $candidates : $pool)->random();
        }

        return $pending->first();
    }

    /**
     * End of the sequence: complete the campaign or start the next cycle.
     *
     * @return bool true when a new cycle was started.
     */
    public function completeCycle(Campaign $campaign, CarbonImmutable $now): bool
    {
        $campaign->cycles_completed++;
        $mode = $campaign->repeat_mode;
        $limitReached = $mode->supportsLimit()
            && $campaign->repeat_limit !== null
            && $campaign->cycles_completed > $campaign->repeat_limit;

        if ($mode === RepeatMode::None || $limitReached) {
            $this->finish($campaign);

            return false;
        }

        $previousStart = $campaign->cycle_started_at ?? $campaign->activated_at ?? $now;
        $this->resetItems($campaign, $campaign->cycle + 1);
        $campaign->cycle++;

        if (in_array($mode, [RepeatMode::Daily, RepeatMode::Weekly], true)) {
            $step = $mode === RepeatMode::Daily ? '1 day' : '1 week';
            $nextStart = $previousStart->add($step);

            while ($nextStart->lessThanOrEqualTo($now)) {
                $nextStart = $nextStart->add($step);
            }

            if ($campaign->trigger_type === TriggerType::Scheduled) {
                $shift = $previousStart->diffInSeconds($nextStart);
                $campaign->items()->whereNotNull('scheduled_at')->get()->each(
                    fn (CampaignItem $i) => $i->forceFill(['scheduled_at' => $i->scheduled_at->addSeconds($shift)])->save()
                );
            }

            $campaign->schedule_anchor_at = $nextStart;
            $campaign->cycle_started_at = $nextStart;
            $campaign->next_run_at = match ($campaign->trigger_type) {
                TriggerType::Manual => null,
                TriggerType::Interval => $nextStart,
                default => $this->calculator->nextRun($campaign, $nextStart->subSecond()),
            };
        } else {
            $campaign->cycle_started_at = $now;
            $campaign->next_run_at = $campaign->next_run_at && $campaign->next_run_at->greaterThan($now)
                ? $campaign->next_run_at
                : $this->calculator->nextRun($campaign, $now);

            if ($campaign->trigger_type === TriggerType::Interval && $campaign->next_run_at === null) {
                $campaign->next_run_at = $now;
            }
        }

        if ($campaign->trigger_type !== TriggerType::Manual && $campaign->next_run_at === null) {
            // The end date leaves no room for another cycle.
            $this->finish($campaign);

            return false;
        }

        $campaign->save();

        Log::channel('campaign')->info('Campaign cycle restarted', ['campaign_id' => $campaign->id, 'cycle' => $campaign->cycle]);

        return true;
    }

    private function finish(Campaign $campaign): void
    {
        $from = $campaign->status;
        $campaign->status = CampaignStatus::Completed;
        $campaign->completed_at = CarbonImmutable::now();
        $campaign->next_run_at = null;
        $campaign->save();
        $this->statusChanged($campaign, $from);
    }

    private function resetItems(Campaign $campaign, int $cycle): void
    {
        $campaign->campaignItems()
            ->where('status', '!=', ItemStatus::Processing)
            ->update([
                'status' => ItemStatus::Pending,
                'cycle' => $cycle,
                'attempts' => 0,
                'failed_attempts' => 0,
                'next_attempt_at' => null,
                'processing_started_at' => null,
                'sent_at' => null,
                'current_log_id' => null,
                'telegram_message_id' => null,
                'error_message' => null,
                'updated_at' => CarbonImmutable::now(),
            ]);
    }

    private function hasOpenItems(Campaign $campaign): bool
    {
        return $campaign->campaignItems()
            ->whereIn('status', [ItemStatus::Pending, ItemStatus::Processing])
            ->exists();
    }

    private function resumeRunTime(Campaign $campaign, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($campaign->trigger_type === TriggerType::Interval) {
            // Before the start date the original plan stands.
            if ($campaign->start_at !== null && $campaign->start_at->greaterThan($now)) {
                $campaign->schedule_anchor_at = $campaign->start_at;

                return $campaign->start_at;
            }

            // Interval campaigns post right away on resume, then continue every interval.
            $campaign->schedule_anchor_at = $now;

            return $now;
        }

        return $campaign->trigger_type === TriggerType::Manual
            ? null
            : $this->calculator->nextRun($campaign, $now->subSecond());
    }

    /**
     * "Scheduled" means waiting for the start date; otherwise the campaign is running.
     */
    private function startingStatus(Campaign $campaign, CarbonImmutable $now): CampaignStatus
    {
        return $campaign->start_at !== null && $campaign->start_at->greaterThan($now)
            ? CampaignStatus::Scheduled
            : CampaignStatus::Running;
    }

    /**
     * @param  list<CampaignStatus>  $allowed
     */
    private function assertStatus(Campaign $campaign, array $allowed, string $verb): void
    {
        if (! in_array($campaign->status, $allowed, true)) {
            throw ValidationException::withMessages(['campaign' => "A {$campaign->status->label()} campaign cannot be {$verb}."]);
        }
    }

    private function lock(Campaign $campaign): Campaign
    {
        return Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
    }

    private function statusChanged(Campaign $campaign, CampaignStatus $from): void
    {
        if ($from !== $campaign->status) {
            DB::afterCommit(fn () => event(new CampaignStatusChanged($campaign->fresh() ?? $campaign, $from, $campaign->status)));
        }
    }
}
