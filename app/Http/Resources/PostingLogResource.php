<?php

namespace App\Http\Resources;

use App\Models\PostingLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PostingLog
 */
class PostingLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'campaign_id' => $this->campaign_id,
            'campaign_item_id' => $this->campaign_item_id,
            'bot_id' => $this->bot_id,
            'destination_id' => $this->destination_id,
            'content_id' => $this->content_id,
            'content_title' => $this->whenLoaded('content', fn () => $this->content?->title),
            'status' => $this->status->value,
            'trigger' => $this->trigger->value,
            'is_test' => $this->is_test,
            'chat_id' => $this->chat_id,
            'cycle' => $this->cycle,
            'attempts' => $this->attempts,
            'telegram_message_id' => $this->telegram_message_id,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'response_data' => $this->when($request->boolean('with_response'), $this->response_data),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
