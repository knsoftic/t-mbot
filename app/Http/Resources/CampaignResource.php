<?php

namespace App\Http\Resources;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\Schedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'bot_id' => $this->bot_id,
            'destination_id' => $this->destination_id,
            'test_mode' => $this->test_mode,
            'test_destination_id' => $this->test_destination_id,
            'posting_order' => $this->posting_order->value,
            'trigger_type' => $this->trigger_type->value,
            'timezone' => $this->timezone,
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'interval_seconds' => $this->interval_seconds,
            'slots' => $this->whenLoaded('schedules', fn () => $this->schedules->map(fn (Schedule $s) => [
                'time' => substr((string) $s->time_of_day, 0, 5),
                'days' => $s->days,
            ])),
            'repeat_mode' => $this->repeat_mode->value,
            'repeat_limit' => $this->repeat_limit,
            'failure_behavior' => $this->failure_behavior->value,
            'max_retries' => $this->max_retries,
            'retry_delay_seconds' => $this->retry_delay_seconds,
            'cycle' => $this->cycle,
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'progress' => $this->progress(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (CampaignItem $item) => [
                'id' => $item->id,
                'content_id' => $item->content_id,
                'content_title' => $item->content?->title,
                'sort_order' => $item->sort_order,
                'status' => $item->status->value,
                'attempts' => $item->attempts,
                'scheduled_at' => $item->scheduled_at?->toIso8601String(),
                'next_attempt_at' => $item->next_attempt_at?->toIso8601String(),
                'sent_at' => $item->sent_at?->toIso8601String(),
                'telegram_message_id' => $item->telegram_message_id,
                'error_message' => $item->error_message,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
