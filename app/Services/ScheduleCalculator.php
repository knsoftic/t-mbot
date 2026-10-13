<?php

namespace App\Services;

use App\Enums\ItemStatus;
use App\Enums\TriggerType;
use App\Models\Campaign;
use App\Models\Schedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Computes when a campaign should post next.
 *
 * All inputs and outputs are UTC instants; campaign timezones are only used
 * to interpret wall-clock times such as "every day at 09:00".
 */
class ScheduleCalculator
{
    /**
     * When the first post should happen after activation (may be "now").
     */
    public function firstRun(Campaign $campaign, CarbonImmutable $now): ?CarbonImmutable
    {
        $start = $campaign->start_at;

        $next = match ($campaign->trigger_type) {
            TriggerType::Manual => null,
            TriggerType::Scheduled => $this->nextScheduledItemTime($campaign),
            TriggerType::Interval => $start !== null && $start->greaterThan($now) ? $start : $now,
            default => $this->nextSlot($this->slots($campaign), $campaign->timezone, $now->subSecond(), $start),
        };

        return $this->applyEnd($campaign, $next);
    }

    /**
     * Next run strictly after $after.
     */
    public function nextRun(Campaign $campaign, CarbonImmutable $after): ?CarbonImmutable
    {
        $next = match ($campaign->trigger_type) {
            TriggerType::Manual => null,
            TriggerType::Scheduled => $this->nextScheduledItemTime($campaign),
            TriggerType::Interval => $this->nextInterval(
                $campaign->schedule_anchor_at ?? $campaign->start_at ?? $after,
                (int) $campaign->interval_seconds,
                $after,
            ),
            default => $this->nextSlot($this->slots($campaign), $campaign->timezone, $after, $campaign->start_at),
        };

        return $this->applyEnd($campaign, $next);
    }

    /**
     * First interval slot (anchor + k * interval) strictly after $after.
     */
    public function nextInterval(CarbonInterface $anchor, int $intervalSeconds, CarbonInterface $after): ?CarbonImmutable
    {
        if ($intervalSeconds <= 0) {
            return null;
        }

        $anchor = CarbonImmutable::instance($anchor)->utc();
        $after = CarbonImmutable::instance($after)->utc();

        if ($anchor->greaterThan($after)) {
            return $anchor;
        }

        $elapsed = $after->getTimestamp() - $anchor->getTimestamp();
        $steps = intdiv($elapsed, $intervalSeconds) + 1;

        return $anchor->addSeconds($steps * $intervalSeconds);
    }

    /**
     * Earliest wall-clock slot strictly after $after.
     *
     * @param  list<array{time: string, days: list<int>}>  $slots  Times as "HH:MM[:SS]", ISO weekdays.
     */
    public function nextSlot(array $slots, string $timezone, CarbonInterface $after, ?CarbonInterface $notBefore = null): ?CarbonImmutable
    {
        if ($slots === []) {
            return null;
        }

        $after = CarbonImmutable::instance($after)->utc();

        if ($notBefore !== null && CarbonImmutable::instance($notBefore)->greaterThan($after)) {
            $after = CarbonImmutable::instance($notBefore)->utc()->subSecond();
        }

        $localDay = $after->setTimezone($timezone)->startOfDay();
        $best = null;

        // Eight days always contains the next occurrence of any weekly slot.
        for ($offset = 0; $offset <= 8; $offset++) {
            $day = $localDay->addDays($offset);

            foreach ($slots as $slot) {
                if (! in_array($day->dayOfWeekIso, $slot['days'], true)) {
                    continue;
                }

                $candidate = CarbonImmutable::parse($day->format('Y-m-d').' '.$slot['time'], $timezone)->utc();

                if ($candidate->greaterThan($after) && ($best === null || $candidate->lessThan($best))) {
                    $best = $candidate;
                }
            }

            if ($best !== null) {
                return $best;
            }
        }

        return $best;
    }

    /**
     * @return list<array{time: string, days: list<int>}>
     */
    public function slots(Campaign $campaign): array
    {
        $schedules = $campaign->relationLoaded('schedules') ? $campaign->schedules : $campaign->schedules()->get();

        return $schedules
            ->filter(fn (Schedule $s) => $s->isActive())
            ->map(fn (Schedule $s) => ['time' => (string) $s->time_of_day, 'days' => $s->weekdays()])
            ->values()
            ->all();
    }

    /**
     * For the "scheduled" trigger: publish time of the next pending item in sequence order.
     */
    public function nextScheduledItemTime(Campaign $campaign): ?CarbonImmutable
    {
        $at = $campaign->items()
            ->where('status', ItemStatus::Pending)
            ->whereNotNull('scheduled_at')
            ->value('scheduled_at');

        return $at ? CarbonImmutable::parse($at, 'UTC') : null;
    }

    private function applyEnd(Campaign $campaign, ?CarbonImmutable $next): ?CarbonImmutable
    {
        if ($next !== null && $campaign->end_at !== null && $next->greaterThan($campaign->end_at)) {
            return null;
        }

        return $next;
    }

    /**
     * Upcoming run times for previews (wizard review step, schedules page).
     *
     * @return list<CarbonImmutable>
     */
    public function preview(Campaign $campaign, int $count, ?CarbonImmutable $from = null): array
    {
        $from ??= CarbonImmutable::now();
        $runs = [];

        if ($campaign->trigger_type === TriggerType::Scheduled) {
            return $campaign->items
                ->pluck('scheduled_at')
                ->filter()
                ->take($count)
                ->map(fn ($at) => CarbonImmutable::instance($at))
                ->values()
                ->all();
        }

        $probe = clone $campaign;
        $probe->schedule_anchor_at ??= $probe->start_at && $probe->start_at->greaterThan($from) ? $probe->start_at : $from;
        $next = $this->firstRun($probe, $from);

        while ($next !== null && count($runs) < $count) {
            $runs[] = $next;
            $next = $this->nextRun($probe, $next);
        }

        return $runs;
    }
}
