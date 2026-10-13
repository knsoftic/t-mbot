<?php

namespace App\Http\Resources;

use App\Models\TelegramBot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TelegramBot
 *
 * The bot token is deliberately absent; only a masked form is exposed.
 */
class BotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'telegram_id' => $this->telegram_id,
            'masked_token' => $this->maskedToken(),
            'status' => $this->status->value,
            'connection_status' => $this->connection_status->value,
            'last_error' => $this->last_error,
            'description' => $this->description,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'destinations_count' => $this->whenCounted('destinations'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
