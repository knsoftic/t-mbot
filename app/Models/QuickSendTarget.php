<?php

namespace App\Models;

use App\Enums\ParseMode;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A saved bot → channel/group pair used by Quick Send.
 */
#[Fillable([
    'user_id', 'name', 'bot_id', 'destination_id', 'parse_mode', 'disable_notification',
    'disable_web_page_preview', 'protect_content', 'sort_order',
])]
class QuickSendTarget extends Model
{
    use BelongsToAccount;

    protected function casts(): array
    {
        return [
            'parse_mode' => ParseMode::class,
            'disable_notification' => 'boolean',
            'disable_web_page_preview' => 'boolean',
            'protect_content' => 'boolean',
            'last_used_at' => 'immutable_datetime',
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
     * @return HasMany<PostingLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(PostingLog::class);
    }

    /**
     * Bot API options for sendMessage.
     *
     * @return array<string, mixed>
     */
    public function sendOptions(): array
    {
        return array_filter([
            'parse_mode' => $this->parse_mode?->value,
            'link_preview_options' => $this->disable_web_page_preview ? ['is_disabled' => true] : null,
            'disable_notification' => $this->disable_notification ?: null,
            'protect_content' => $this->protect_content ?: null,
        ], fn ($value) => $value !== null);
    }
}
