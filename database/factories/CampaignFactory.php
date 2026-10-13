<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Enums\FailureBehavior;
use App\Enums\ItemStatus;
use App\Enums\PostingOrder;
use App\Enums\RepeatMode;
use App\Enums\TriggerType;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'bot_id' => TelegramBot::factory(),
            'destination_id' => fn (array $attributes) => TelegramDestination::factory()->create(['bot_id' => $attributes['bot_id']])->id,
            'status' => CampaignStatus::Draft,
            'posting_order' => PostingOrder::Sequential,
            'trigger_type' => TriggerType::Interval,
            'timezone' => 'Asia/Karachi',
            'interval_seconds' => 1800,
            'repeat_mode' => RepeatMode::None,
            'failure_behavior' => FailureBehavior::RetryThenContinue,
            'max_retries' => 3,
            'retry_delay_seconds' => 60,
        ];
    }

    /**
     * Attach a sequence of content items (text items by default).
     *
     * @param  int|list<Content>  $contents
     */
    public function withItems(int|array $contents = 3): static
    {
        return $this->afterCreating(function (Campaign $campaign) use ($contents) {
            $list = is_int($contents) ? Content::factory()->count($contents)->create()->all() : $contents;

            foreach (array_values($list) as $index => $content) {
                $campaign->campaignItems()->create([
                    'content_id' => $content->id,
                    'sort_order' => $index + 1,
                    'status' => ItemStatus::Pending,
                ]);
            }
        });
    }
}
