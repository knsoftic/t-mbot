<?php

namespace App\Models;

use App\Enums\BotStatus;
use App\Enums\ConnectionStatus;
use App\Models\Concerns\BelongsToAccount;
use Database\Factories\TelegramBotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['user_id', 'name', 'username', 'token', 'status', 'description', 'created_by'])]
#[Hidden(['token', 'token_hash', 'webhook_secret'])]
class TelegramBot extends Model
{
    /** @use HasFactory<TelegramBotFactory> */
    use BelongsToAccount, HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            // Encrypted at rest with APP_KEY; never serialised (see #[Hidden]).
            'token' => 'encrypted',
            'status' => BotStatus::class,
            'connection_status' => ConnectionStatus::class,
            'last_checked_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'updates_connected_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TelegramBot $bot) {
            if ($bot->isDirty('token')) {
                $bot->token_hash = self::hashToken($bot->token);

                // A webhook belongs to the old token: it has to be connected again.
                if ($bot->exists) {
                    $bot->updates_connected_at = null;
                }
            }
        });
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', trim($token));
    }

    /**
     * @return HasMany<TelegramDestination, $this>
     */
    public function destinations(): HasMany
    {
        return $this->hasMany(TelegramDestination::class, 'bot_id');
    }

    /**
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'bot_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === BotStatus::Active;
    }

    /**
     * Safe representation for the UI: the numeric bot id only, never the secret.
     */
    public function maskedToken(): string
    {
        $id = str_contains((string) $this->token, ':') ? strstr((string) $this->token, ':', true) : '';

        return ($id !== '' ? $id : '••••').':••••••••••';
    }

    public function displayUsername(): string
    {
        return $this->username ? '@'.$this->username : '—';
    }

    /**
     * @param  Builder<TelegramBot>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', BotStatus::Active);
    }
}
