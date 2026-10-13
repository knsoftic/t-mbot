<?php

namespace App\Http\Resources;

use App\Models\TelegramDestination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TelegramDestination
 */
class DestinationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bot_id' => $this->bot_id,
            'bot' => $this->whenLoaded('bot', fn () => ['id' => $this->bot?->id, 'name' => $this->bot?->name]),
            'name' => $this->name,
            'chat_id' => $this->chat_id,
            'username' => $this->username,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'connection_status' => $this->connection_status->value,
            'telegram_title' => $this->telegram_title,
            'bot_is_admin' => $this->bot_is_admin,
            'bot_can_post' => $this->bot_can_post,
            'is_test_chat' => $this->is_test_chat,
            'last_error' => $this->last_error,
            'description' => $this->description,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
