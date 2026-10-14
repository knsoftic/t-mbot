<?php

namespace App\Services;

use App\Enums\CampaignStatus;
use App\Enums\FailureBehavior;
use App\Enums\ItemStatus;
use App\Enums\PostingOrder;
use App\Enums\RepeatMode;
use App\Enums\TriggerType;
use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\Content;
use App\Models\Schedule;
use App\Models\Scopes\AccountScope;
use App\Models\TelegramBot;
use App\Models\User;
use App\Support\Accounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates/updates campaigns together with their sequence and time slots, and
 * implements the sequence-builder operations.
 */
class CampaignBuilder
{
    public function __construct(
        private readonly ScheduleCalculator $calculator,
        private readonly AuditLogger $audit,
        private readonly PlanLimiter $limiter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'bot_id' => ['required', 'integer', Accounts::exists('telegram_bots')],
            'destination_id' => ['required', 'integer', Accounts::exists('telegram_destinations')],
            'test_mode' => ['boolean'],
            'test_destination_id' => ['nullable', 'required_if:test_mode,true', 'integer', Accounts::exists('telegram_destinations')],
            'posting_order' => ['required', Rule::enum(PostingOrder::class)],
            'trigger_type' => ['required', Rule::enum(TriggerType::class)],
            'timezone' => ['required', 'timezone:all'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after:start_at'],
            'interval_seconds' => ['nullable', 'required_if:trigger_type,interval', 'integer', 'min:60', 'max:2678400'],
            'repeat_mode' => ['required', Rule::enum(RepeatMode::class)],
            'repeat_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'failure_behavior' => ['required', Rule::enum(FailureBehavior::class)],
            'max_retries' => ['required', 'integer', 'min:0', 'max:20'],
            'retry_delay_seconds' => ['required', 'integer', 'min:5', 'max:86400'],
            'items' => ['array', 'max:5000'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.content_id' => ['required', 'integer', Accounts::exists('contents')],
            'items.*.scheduled_at' => ['nullable', 'date'],
            'slots' => ['array', 'max:48'],
            'slots.*.time' => ['required', 'date_format:H:i'],
            'slots.*.days' => ['array'],
            'slots.*.days.*' => ['integer', 'between:1,7'],
        ];
    }

    /**
     * Create or update a campaign. Dates in $data are wall-clock times in $data['timezone'].
     *
     * @param  array<string, mixed>  $data  Validated data.
     */
    public function save(array $data, ?Campaign $campaign = null, ?User $user = null): Campaign
    {
        if ($campaign && $campaign->status === CampaignStatus::Running) {
            throw ValidationException::withMessages(['campaign' => 'Pause the campaign before editing it.']);
        }

        $isNew = $campaign === null;

        // A campaign belongs to the account that owns its bot.
        $ownerId = $this->botOwner((int) $data['bot_id']);

        if ($isNew || (string) $ownerId !== (string) $campaign->user_id) {
            $this->limiter->assertCanAdd($ownerId, 'max_campaigns', 'name');
        }

        $this->assertIntervalAllowed($ownerId, $data);
        $this->assertContentsBelongTo($ownerId, array_column($data['items'] ?? [], 'content_id'));

        $campaign = DB::transaction(function () use ($data, $campaign, $user, $ownerId) {
            $campaign = $campaign
                ? Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail()
                : new Campaign(['status' => CampaignStatus::Draft, 'created_by' => $user?->id]);

            $campaign->user_id = $ownerId;
            $tz = $data['timezone'];
            $trigger = TriggerType::from($data['trigger_type']);
            $repeat = RepeatMode::from($data['repeat_mode']);

            if ($trigger === TriggerType::Scheduled && in_array($repeat, [RepeatMode::Immediate, RepeatMode::Indefinite], true)) {
                throw ValidationException::withMessages(['repeat_mode' => 'With exact per-item times, only "Stop", "Repeat daily" or "Repeat weekly" are possible.']);
            }

            $campaign->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'bot_id' => $data['bot_id'],
                'destination_id' => $data['destination_id'],
                'test_mode' => (bool) ($data['test_mode'] ?? false),
                'test_destination_id' => $data['test_destination_id'] ?? null,
                'posting_order' => $trigger === TriggerType::Scheduled ? PostingOrder::Sequential : $data['posting_order'],
                'trigger_type' => $trigger,
                'timezone' => $tz,
                'start_at' => $this->toUtc($data['start_at'] ?? null, $tz),
                'end_at' => $this->toUtc($data['end_at'] ?? null, $tz),
                'interval_seconds' => $trigger === TriggerType::Interval ? (int) $data['interval_seconds'] : null,
                'repeat_mode' => $repeat,
                'repeat_limit' => $repeat->supportsLimit() ? ($data['repeat_limit'] ?? null) : null,
                'failure_behavior' => $data['failure_behavior'],
                'max_retries' => (int) $data['max_retries'],
                'retry_delay_seconds' => (int) $data['retry_delay_seconds'],
            ]);

            $this->assertDestinationsBelongToBot($campaign);
            $scheduleChanged = $campaign->isDirty(['trigger_type', 'start_at', 'end_at', 'interval_seconds', 'timezone']);
            $campaign->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($campaign, $data['items'], $tz);
            }

            $slotsChanged = $this->syncSlots($campaign, $trigger, $data['slots'] ?? [], $tz);

            // Re-plan an already active (but not running) campaign whose timing changed.
            if (($scheduleChanged || $slotsChanged) && in_array($campaign->status, [CampaignStatus::Scheduled, CampaignStatus::Paused], true)) {
                $now = CarbonImmutable::now();
                $campaign->schedule_anchor_at = $campaign->start_at && $campaign->start_at->greaterThan($now) ? $campaign->start_at : $now;
                $campaign->setRelation('schedules', $campaign->schedules()->get());
                $campaign->next_run_at = $this->calculator->firstRun($campaign, $now);
                $campaign->save();
            }

            return $campaign;
        });

        $this->audit->log(
            $isNew ? 'campaign.created' : 'campaign.updated',
            $campaign,
            ($isNew ? 'Campaign created: ' : 'Campaign edited: ').$campaign->name,
            $isNew ? [] : $this->audit->changes($campaign),
            $user,
        );

        return $campaign->refresh();
    }

    public function deleteCampaign(Campaign $campaign, ?User $user = null): void
    {
        if (in_array($campaign->status, [CampaignStatus::Running, CampaignStatus::Scheduled], true)) {
            throw ValidationException::withMessages(['campaign' => 'Stop the campaign before deleting it.']);
        }

        $campaign->delete();
        $this->audit->log('campaign.deleted', $campaign, "Campaign \"{$campaign->name}\" deleted", [], $user);
    }

    // ------------------------------------------------------------------
    // Sequence builder operations
    // ------------------------------------------------------------------

    /**
     * @param  list<int>  $contentIds
     */
    public function addContents(Campaign $campaign, array $contentIds, ?User $user = null): int
    {
        $this->assertContentsBelongTo($campaign->user_id, $contentIds);
        $ids = Content::query()->whereIn('id', $contentIds)->pluck('id')->all();
        $order = array_values(array_filter($contentIds, fn ($id) => in_array((int) $id, $ids, true)));

        DB::transaction(function () use ($campaign, $order) {
            $this->lockCampaign($campaign);
            $next = (int) $campaign->campaignItems()->max('sort_order');

            foreach ($order as $contentId) {
                $campaign->campaignItems()->create([
                    'content_id' => $contentId,
                    'sort_order' => ++$next,
                    'status' => ItemStatus::Pending,
                    'cycle' => $campaign->cycle,
                ]);
            }
        });

        $this->audit->log('campaign.items_added', $campaign, count($order).' item(s) added to the sequence', ['content_ids' => $order], $user);

        return count($order);
    }

    /**
     * Move an item to a zero-based position (drag & drop).
     */
    public function moveTo(CampaignItem $item, int $position): void
    {
        DB::transaction(function () use ($item, $position) {
            $campaign = $this->lockCampaign($item->campaign);
            $ids = $campaign->items()->pluck('id')->reject(fn ($id) => $id === $item->id)->values()->all();
            $position = max(0, min($position, count($ids)));
            array_splice($ids, $position, 0, [$item->id]);
            $this->writeOrder($ids);
        });
    }

    public function moveBy(CampaignItem $item, int $delta): void
    {
        $ids = $item->campaign->items()->pluck('id')->all();
        $index = array_search($item->id, $ids, true);

        if ($index !== false) {
            $this->moveTo($item, (int) $index + $delta);
        }
    }

    public function duplicateItem(CampaignItem $item): CampaignItem
    {
        return DB::transaction(function () use ($item) {
            $campaign = $this->lockCampaign($item->campaign);
            $copy = $campaign->campaignItems()->create([
                'content_id' => $item->content_id,
                'sort_order' => $item->sort_order,
                'status' => ItemStatus::Pending,
                'cycle' => $campaign->cycle,
                'scheduled_at' => $item->scheduled_at,
            ]);

            $ids = $campaign->items()->pluck('id')->reject(fn ($id) => $id === $copy->id)->values()->all();
            $index = array_search($item->id, $ids, true);
            array_splice($ids, $index === false ? count($ids) : $index + 1, 0, [$copy->id]);
            $this->writeOrder($ids);

            return $copy;
        });
    }

    public function removeItem(CampaignItem $item, ?User $user = null): void
    {
        DB::transaction(function () use ($item) {
            $campaign = $this->lockCampaign($item->campaign);
            $fresh = CampaignItem::query()->whereKey($item->id)->lockForUpdate()->first();

            if (! $fresh) {
                return;
            }

            if ($fresh->status === ItemStatus::Processing) {
                throw ValidationException::withMessages(['item' => 'This item is being delivered right now and cannot be removed.']);
            }

            // Posting logs keep their history; their campaign_item_id becomes null.
            $fresh->delete();
            $this->writeOrder($campaign->items()->pluck('id')->all());
        });

        $this->audit->log('campaign.item_removed', $item->campaign, "Item #{$item->sort_order} removed from the sequence", ['content_id' => $item->content_id], $user);
    }

    public function setItemSchedule(CampaignItem $item, ?string $localDateTime): void
    {
        $item->update(['scheduled_at' => $this->toUtc($localDateTime, $item->campaign->timezone)]);
    }

    // ------------------------------------------------------------------

    /**
     * @param  list<array{id?: int|null, content_id: int, scheduled_at?: string|null}>  $items
     */
    private function syncItems(Campaign $campaign, array $items, string $tz): void
    {
        $existing = $campaign->campaignItems()->get()->keyBy('id');
        $keep = [];

        foreach (array_values($items) as $index => $row) {
            $id = isset($row['id']) ? (int) $row['id'] : null;
            $attributes = [
                'content_id' => (int) $row['content_id'],
                'sort_order' => $index + 1,
                'scheduled_at' => $this->toUtc($row['scheduled_at'] ?? null, $tz),
            ];

            if ($id && $existing->has($id)) {
                $existing[$id]->update($attributes);
                $keep[] = $id;
            } else {
                $keep[] = $campaign->campaignItems()->create($attributes + [
                    'status' => ItemStatus::Pending,
                    'cycle' => $campaign->cycle,
                ])->id;
            }
        }

        $removable = $existing->keys()->diff($keep);

        if ($removable->isNotEmpty()) {
            if ($campaign->campaignItems()->whereIn('id', $removable)->where('status', ItemStatus::Processing)->exists()) {
                throw ValidationException::withMessages(['items' => 'An item that is being delivered cannot be removed.']);
            }

            $campaign->campaignItems()->whereIn('id', $removable)->delete();
        }
    }

    /**
     * @param  list<array{time: string, days?: list<int>}>  $slots
     */
    private function syncSlots(Campaign $campaign, TriggerType $trigger, array $slots, string $tz): bool
    {
        $before = $campaign->schedules()->get()->map(fn (Schedule $s) => $s->time_of_day.'|'.json_encode($s->days).'|'.$s->timezone)->sort()->values()->all();
        $campaign->schedules()->delete();

        if ($trigger->usesTimeSlots()) {
            if ($trigger === TriggerType::Daily) {
                $slots = array_slice($slots, 0, 1);
            }

            foreach ($slots as $slot) {
                $days = $trigger === TriggerType::Weekly ? array_values(array_unique(array_map('intval', $slot['days'] ?? []))) : null;

                if ($trigger === TriggerType::Weekly && $days === []) {
                    throw ValidationException::withMessages(['slots' => 'Select at least one weekday.']);
                }

                $campaign->schedules()->create([
                    'schedule_type' => $trigger->value,
                    'time_of_day' => $slot['time'].':00',
                    'days' => $days ? array_values(array_map('intval', $days)) : null,
                    'timezone' => $tz,
                    'status' => 'active',
                ]);
            }
        }

        $after = $campaign->schedules()->get()->map(fn (Schedule $s) => $s->time_of_day.'|'.json_encode($s->days).'|'.$s->timezone)->sort()->values()->all();

        return $before !== $after;
    }

    private function botOwner(int $botId): ?int
    {
        $owner = TelegramBot::withTrashed()->withoutGlobalScope(AccountScope::class)->whereKey($botId)->value('user_id');

        return $owner === null ? null : (int) $owner;
    }

    /**
     * A customer's campaign may only post that customer's own content.
     *
     * @param  array<int|string>  $contentIds
     */
    private function assertContentsBelongTo(?int $ownerId, array $contentIds): void
    {
        if ($contentIds === [] || ! Accounts::isCustomer($ownerId)) {
            return;
        }

        $foreign = Content::withTrashed()->withoutGlobalScope(AccountScope::class)
            ->whereIn('id', array_map('intval', $contentIds))
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $ownerId))
            ->exists();

        if ($foreign) {
            throw ValidationException::withMessages(['items' => 'The sequence can only use content from the same account as the bot.']);
        }
    }

    /**
     * Plans set the shortest posting interval.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertIntervalAllowed(?int $ownerId, array $data): void
    {
        if (($data['trigger_type'] ?? null) !== TriggerType::Interval->value) {
            return;
        }

        $min = $this->limiter->minIntervalSeconds($ownerId);

        if ((int) ($data['interval_seconds'] ?? 0) < $min) {
            throw ValidationException::withMessages([
                'interval_seconds' => 'Your plan allows posting at most every '.intdiv($min, 60).' minute(s). Choose a longer interval or upgrade your plan.',
            ]);
        }
    }

    private function assertDestinationsBelongToBot(Campaign $campaign): void
    {
        $destination = $campaign->destination()->first();

        if ($destination && $destination->bot_id !== (int) $campaign->bot_id) {
            throw ValidationException::withMessages(['destination_id' => 'This destination is connected to a different bot.']);
        }

        if ($campaign->test_destination_id) {
            $test = $campaign->testDestination()->first();

            if ($test && $test->bot_id !== (int) $campaign->bot_id) {
                throw ValidationException::withMessages(['test_destination_id' => 'The test chat must be connected to the same bot.']);
            }
        }
    }

    /**
     * @param  list<int>  $orderedIds
     */
    private function writeOrder(array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $index => $id) {
            CampaignItem::query()->whereKey($id)->where('sort_order', '!=', $index + 1)->update(['sort_order' => $index + 1]);
        }
    }

    private function lockCampaign(Campaign $campaign): Campaign
    {
        return Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
    }

    public function toUtc(?string $local, string $timezone): ?CarbonImmutable
    {
        if ($local === null || trim($local) === '') {
            return null;
        }

        return CarbonImmutable::parse($local, $timezone)->utc();
    }
}
