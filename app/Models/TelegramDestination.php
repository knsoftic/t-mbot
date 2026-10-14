<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Enums\DestinationStatus;
use App\Enums\DestinationType;
use App\Enums\ParseMode;
use App\Models\Concerns\BelongsToAccount;
use Database\Factories\TelegramDestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id', 'bot_id', 'name', 'chat_id', 'username', 'type', 'status', 'description', 'is_test_chat', 'created_by',
    'welcome_enabled', 'welcome_message', 'welcome_parse_mode', 'welcome_button_text', 'welcome_button_url',
    'welcome_direct_joins', 'auto_approve_requests',
])]
class TelegramDestination extends Model
{
    /** @use HasFactory<TelegramDestinationFactory> */
    use BelongsToAccount, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => DestinationType::class,
            'status' => DestinationStatus::class,
            'connection_status' => ConnectionStatus::class,
            'bot_is_admin' => 'boolean',
            'bot_can_post' => 'boolean',
            'is_test_chat' => 'boolean',
            'welcome_enabled' => 'boolean',
            'welcome_parse_mode' => ParseMode::class,
            'welcome_direct_joins' => 'boolean',
            'auto_approve_requests' => 'boolean',
            'last_checked_at' => 'datetime',
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
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'destination_id');
    }

    public function isActive(): bool
    {
        return $this->status === DestinationStatus::Active;
    }

    /**
     * Normalised chat identifier for the Bot API: numeric ids stay numeric,
     * public usernames are prefixed with "@".
     */
    public function apiChatId(): string
    {
        return self::normalizeChatId($this->chat_id);
    }

    public static function normalizeChatId(string $chatId): string
    {
        $chatId = trim($chatId);

        if (preg_match('/^-?\d+$/', $chatId)) {
            return $chatId;
        }

        $chatId = preg_replace('#^(https?://)?(t\.me|telegram\.me)/#i', '', $chatId);

        return '@'.ltrim((string) $chatId, '@');
    }

    /**
     * @param  Builder<TelegramDestination>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', DestinationStatus::Active);
    }
}
