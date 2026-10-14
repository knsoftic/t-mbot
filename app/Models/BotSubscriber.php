<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Telegram user who pressed Start in one of the bots (so the bot may message them).
 */
#[Fillable(['user_id', 'bot_id', 'telegram_user_id', 'first_name', 'last_name', 'username', 'started_at', 'blocked_at'])]
class BotSubscriber extends Model
{
    use BelongsToAccount;

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'blocked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<TelegramBot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(TelegramBot::class, 'bot_id');
    }
}
