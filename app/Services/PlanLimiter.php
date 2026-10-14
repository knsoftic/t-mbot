<?php

namespace App\Services;

use App\Enums\DestinationType;
use App\Enums\PostingStatus;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\Plan;
use App\Models\PostingLog;
use App\Models\Scopes\AccountScope;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Applies a customer's plan: resource limits, features and the daily /
 * weekly / monthly sending limits. Staff accounts have no limits.
 */
class PlanLimiter
{
    /**
     * The plan limiting this account, or null when it is unlimited (staff / no owner).
     */
    public function planFor(User|int|null $owner): ?Plan
    {
        $owner = $this->user($owner);

        return $owner?->currentPlan();
    }

    /**
     * How many of a countable resource the account has now.
     */
    public function count(int $userId, string $column): int
    {
        $owned = fn (string $model): Builder => $model::query()->withoutGlobalScope(AccountScope::class)->where('user_id', $userId);

        return match ($column) {
            'max_bots' => $owned(TelegramBot::class)->count(),
            'max_channels' => $owned(TelegramDestination::class)->where('type', DestinationType::Channel)->count(),
            'max_groups' => $owned(TelegramDestination::class)->whereIn('type', DestinationType::groupTypes())->count(),
            'max_campaigns' => $owned(Campaign::class)->count(),
            'max_contents' => $owned(Content::class)->count(),
        };
    }

    /**
     * Stop the account from going over a resource limit.
     *
     * @throws ValidationException
     */
    public function assertCanAdd(User|int|null $owner, string $column, string $errorKey = 'plan'): void
    {
        $owner = $this->user($owner);
        $plan = $owner?->currentPlan();
        $limit = $plan?->limit($column);

        if ($plan === null || $limit === null) {
            return;
        }

        $used = $this->count($owner->id, $column);

        if ($used >= $limit) {
            $label = Str::plural(Str::singular(mb_strtolower(Plan::RESOURCES[$column])), $limit);

            throw ValidationException::withMessages([
                $errorKey => "Your {$plan->name} plan allows {$limit} {$label} and you already have {$used}. Upgrade your plan (Billing → Plans) to add more.",
            ]);
        }
    }

    /**
     * Stop the account from using a feature its plan does not include.
     *
     * @throws ValidationException
     */
    public function assertFeature(User|int|null $owner, string $feature, string $errorKey = 'plan'): void
    {
        $plan = $this->planFor($owner);

        if ($plan !== null && ! $plan->getAttribute($feature)) {
            throw ValidationException::withMessages([
                $errorKey => Plan::FEATURES[$feature]." is not included in your {$plan->name} plan. Upgrade your plan (Billing → Plans) to use it.",
            ]);
        }
    }

    public function allows(User|int|null $owner, string $feature): bool
    {
        $plan = $this->planFor($owner);

        return $plan === null || (bool) $plan->getAttribute($feature);
    }

    /**
     * Shortest posting interval the plan allows, in seconds.
     */
    public function minIntervalSeconds(User|int|null $owner): int
    {
        $plan = $this->planFor($owner);

        return $plan === null ? 60 : max(1, (int) $plan->min_interval_minutes) * 60;
    }

    /**
     * Largest upload the plan allows, in kilobytes (null = no plan limit).
     */
    public function maxUploadKilobytes(User|int|null $owner): ?int
    {
        $plan = $this->planFor($owner);

        return $plan?->max_upload_mb ? (int) $plan->max_upload_mb * 1024 : null;
    }

    /**
     * Day / week / month windows in the account's own timezone, as UTC.
     *
     * @return array<string, array{period: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function windows(User $owner, ?CarbonImmutable $now = null): array
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone($owner->displayTimezone());
        $day = $local->startOfDay();
        $week = $local->startOfWeek(CarbonImmutable::MONDAY);
        $month = $local->startOfMonth();

        return [
            'posts_per_day' => ['period' => 'day', 'start' => $day->utc(), 'end' => $day->addDay()->utc()],
            'posts_per_week' => ['period' => 'week', 'start' => $week->utc(), 'end' => $week->addWeek()->utc()],
            'posts_per_month' => ['period' => 'month', 'start' => $month->utc(), 'end' => $month->addMonthNoOverflow()->utc()],
        ];
    }

    /**
     * Posts sent (or being sent) by the account since $since.
     */
    public function postsSince(int $userId, CarbonImmutable $since): int
    {
        return PostingLog::query()
            ->withoutGlobalScope(AccountScope::class)
            ->where('user_id', $userId)
            ->whereIn('status', [PostingStatus::Processing, PostingStatus::Sent])
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * When a sending limit is used up: which one and when posting may continue.
     * With several exhausted windows the latest reset wins.
     *
     * @return array{period: string, used: int, limit: int, resets_at: CarbonImmutable}|null
     */
    public function sendingBlock(User $owner, ?CarbonImmutable $now = null): ?array
    {
        $plan = $owner->currentPlan();

        if ($plan === null) {
            return null;
        }

        $block = null;

        foreach ($this->windows($owner, $now) as $column => $window) {
            $limit = $plan->limit($column);

            if ($limit === null) {
                continue;
            }

            $used = $this->postsSince($owner->id, $window['start']);

            if ($used >= $limit && ($block === null || $window['end']->greaterThan($block['resets_at']))) {
                $block = ['period' => $window['period'], 'used' => $used, 'limit' => $limit, 'resets_at' => $window['end']];
            }
        }

        return $block;
    }

    /**
     * Everything the Billing page shows about an account's usage.
     *
     * @return array{plan: Plan|null, resources: list<array{label: string, used: int, limit: int|null}>, sending: list<array{label: string, used: int, limit: int|null, resets_at: CarbonImmutable}>}
     */
    public function usage(User $user): array
    {
        $plan = $user->currentPlan();
        $resources = [];
        $sending = [];

        foreach (Plan::RESOURCES as $column => $label) {
            $resources[] = ['label' => $label, 'used' => $this->count($user->id, $column), 'limit' => $plan?->limit($column)];
        }

        foreach ($this->windows($user) as $column => $window) {
            $sending[] = [
                'label' => 'Posts this '.$window['period'],
                'used' => $this->postsSince($user->id, $window['start']),
                'limit' => $plan?->limit($column),
                'resets_at' => $window['end'],
            ];
        }

        return compact('plan', 'resources', 'sending');
    }

    private function user(User|int|null $owner): ?User
    {
        if ($owner === null || $owner instanceof User) {
            return $owner;
        }

        return auth()->id() === $owner ? auth()->user() : User::query()->find($owner);
    }
}
