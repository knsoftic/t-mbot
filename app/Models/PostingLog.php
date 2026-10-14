<?php

namespace App\Models;

use App\Enums\PostingStatus;
use App\Enums\PostTrigger;
use App\Models\Concerns\BelongsToAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'campaign_id', 'campaign_item_id', 'bot_id', 'destination_id', 'content_id', 'status', 'trigger',
    'is_test', 'chat_id', 'cycle', 'attempts', 'telegram_message_id', 'scheduled_at', 'api_called_at',
    'sent_at', 'duration_ms', 'error_code', 'error_message', 'response_data',
])]
class PostingLog extends Model
{
    use BelongsToAccount;

    protected function casts(): array
    {
        return [
            'status' => PostingStatus::class,
            'trigger' => PostTrigger::class,
            'is_test' => 'boolean',
            'response_data' => 'array',
            'scheduled_at' => 'immutable_datetime',
            'api_called_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class)->withTrashed();
    }

    /**
     * @return BelongsTo<CampaignItem, $this>
     */
    public function campaignItem(): BelongsTo
    {
        return $this->belongsTo(CampaignItem::class);
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
     * @return BelongsTo<Content, $this>
     */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class)->withTrashed();
    }

    /**
     * Apply the filters used by the logs page and the API.
     *
     * @param  Builder<PostingLog>  $query
     * @param  array<string, mixed>  $filters
     */
    public function scopeFilter(Builder $query, array $filters, string $timezone = 'UTC'): void
    {
        $query
            ->when($filters['campaign_id'] ?? null, fn (Builder $q, $v) => $q->where('campaign_id', $v))
            ->when($filters['bot_id'] ?? null, fn (Builder $q, $v) => $q->where('bot_id', $v))
            ->when($filters['destination_id'] ?? null, fn (Builder $q, $v) => $q->where('destination_id', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->where('created_at', '>=', CarbonImmutable::parse($v, $timezone)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->where('created_at', '<=', CarbonImmutable::parse($v, $timezone)->endOfDay()->utc()));
    }
}
