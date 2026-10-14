<?php

namespace Database\Factories;

use App\Enums\DestinationStatus;
use App\Enums\DestinationType;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramDestination>
 */
class TelegramDestinationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bot_id' => TelegramBot::factory(),
            // Same account as the bot, like DestinationService does.
            'user_id' => fn (array $attributes) => TelegramBot::withoutGlobalScopes()->whereKey($attributes['bot_id'])->value('user_id'),
            'name' => fake()->unique()->words(2, true).' Channel',
            'chat_id' => '-100'.fake()->unique()->numerify('##########'),
            'type' => DestinationType::Channel,
            'status' => DestinationStatus::Active,
        ];
    }

    public function group(): static
    {
        return $this->state(fn () => ['type' => DestinationType::Supergroup]);
    }

    public function testChat(): static
    {
        return $this->state(fn () => ['is_test_chat' => true, 'name' => 'Test chat']);
    }
}
