<?php

namespace App\Models;

use App\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['campaign_id', 'content_id', 'sort_order', 'status', 'cycle', 'scheduled_at'])]
class CampaignItem extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ItemStatus::class,
            'sort_order' => 'integer',
            'cycle' => 'integer',
            'attempts' => 'integer',
            'failed_attempts' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
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
     * @return BelongsTo<Content, $this>
     */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class)->withTrashed();
    }

    /**
     * @return BelongsTo<PostingLog, $this>
     */
    public function currentLog(): BelongsTo
    {
        return $this->belongsTo(PostingLog::class, 'current_log_id');
    }

    /**
     * @return HasMany<PostingLog, $this>
     */
    public function postingLogs(): HasMany
    {
        return $this->hasMany(PostingLog::class);
    }

    public function isWaitingForRetry(): bool
    {
        return $this->status === ItemStatus::Pending && $this->next_attempt_at !== null;
    }
}
