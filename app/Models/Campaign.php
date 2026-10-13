<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\FailureBehavior;
use App\Enums\ItemStatus;
use App\Enums\PostingOrder;
use App\Enums\RepeatMode;
use App\Enums\TriggerType;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

#[Fillable([
    'name', 'description', 'bot_id', 'destination_id', 'test_mode', 'test_destination_id', 'status',
    'posting_order', 'trigger_type', 'start_at', 'end_at', 'timezone', 'interval_seconds',
    'repeat_mode', 'repeat_limit', 'failure_behavior', 'max_retries', 'retry_delay_seconds', 'created_by',
])]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Mirror the column defaults so unsaved / freshly created models are complete.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'posting_order' => 'sequential',
        'trigger_type' => 'interval',
        'repeat_mode' => 'none',
        'failure_behavior' => 'retry_then_continue',
        'test_mode' => false,
        'max_retries' => 3,
        'retry_delay_seconds' => 60,
        'cycle' => 1,
        'cycles_completed' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'posting_order' => PostingOrder::class,
            'trigger_type' => TriggerType::class,
            'repeat_mode' => RepeatMode::class,
            'failure_behavior' => FailureBehavior::class,
            'test_mode' => 'boolean',
            'interval_seconds' => 'integer',
            'repeat_limit' => 'integer',
            'max_retries' => 'integer',
            'retry_delay_seconds' => 'integer',
            'cycle' => 'integer',
            'cycles_completed' => 'integer',
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'schedule_anchor_at' => 'immutable_datetime',
            'cycle_started_at' => 'immutable_datetime',
            'next_run_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<TelegramBot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class, 'bot_id')->withTrashed();
    }

    /**
     * @return BelongsTo<TelegramDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(TelegramDestination::class, 'destination_id')->withTrashed();
    }

    /**
     * @return BelongsTo<TelegramDestination, $this>
     */
    public function testDestination(): BelongsTo
    {
        return $this->belongsTo(TelegramDestination::class, 'test_destination_id')->withTrashed();
    }

    /**
     * @return HasMany<CampaignItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CampaignItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Unordered item relation for aggregate / existence queries.
     *
     * @return HasMany<CampaignItem, $this>
     */
    public function campaignItems(): HasMany
    {
        return $this->hasMany(CampaignItem::class);
    }

    /**
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class)->orderBy('time_of_day');
    }

    /**
     * @return HasMany<PostingLog, $this>
     */
    public function postingLogs(): HasMany
    {
        return $this->hasMany(PostingLog::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Where posts actually go: the test chat while Test Mode is on.
     */
    public function deliveryDestination(): ?TelegramDestination
    {
        return $this->test_mode ? $this->testDestination : $this->destination;
    }

    /**
     * All production destinations of this campaign. A single destination today;
     * multi-destination campaigns only need to extend this method and the
     * dispatcher loop in PostingService.
     *
     * @return Collection<int, TelegramDestination>
     */
    public function destinations(): Collection
    {
        return collect([$this->destination])->filter();
    }

    /**
     * Item counts per status for the current cycle.
     *
     * @return array{total: int, pending: int, processing: int, sent: int, failed: int, skipped: int, done: int, percent: int}
     */
    public function progress(): array
    {
        $counts = $this->relationLoaded('items')
            ? $this->items->countBy(fn (CampaignItem $item) => $item->status->value)->all()
            : $this->items()->toBase()->reorder()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->all();

        $stats = ['total' => 0];

        foreach (ItemStatus::cases() as $status) {
            $stats[$status->value] = (int) ($counts[$status->value] ?? 0);
            $stats['total'] += $stats[$status->value];
        }

        $stats['done'] = $stats['sent'] + $stats['failed'] + $stats['skipped'];
        $stats['percent'] = $stats['total'] > 0 ? (int) floor($stats['done'] * 100 / $stats['total']) : 0;

        return $stats;
    }

    public function intervalLabel(): string
    {
        $seconds = (int) $this->interval_seconds;

        if ($seconds <= 0) {
            return '—';
        }

        if ($seconds % 3600 === 0) {
            $hours = intdiv($seconds, 3600);

            return $hours === 1 ? 'Every hour' : "Every {$hours} hours";
        }

        if ($seconds % 60 === 0) {
            $minutes = intdiv($seconds, 60);

            return $minutes === 1 ? 'Every minute' : "Every {$minutes} minutes";
        }

        return "Every {$seconds} seconds";
    }

    /**
     * Human readable description of the trigger configuration.
     */
    public function scheduleSummary(): string
    {
        return match ($this->trigger_type) {
            TriggerType::Manual => 'Manual — Run Now only',
            TriggerType::Scheduled => 'Exact date & time per item',
            TriggerType::Interval => $this->intervalLabel(),
            TriggerType::FixedTime, TriggerType::Daily, TriggerType::Weekly => $this->schedules
                ->map(fn (Schedule $s) => $s->summary())
                ->implode('; ') ?: 'No times configured',
        };
    }

    public function isEditable(): bool
    {
        return ! in_array($this->status, [CampaignStatus::Running], true);
    }

    /**
     * @param  Builder<Campaign>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term !== '') {
            $query->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%');
        }
    }
}
