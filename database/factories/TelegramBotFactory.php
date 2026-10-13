<?php

namespace Database\Factories;

use App\Enums\BotStatus;
use App\Enums\ConnectionStatus;
use App\Models\TelegramBot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TelegramBot>
 */
class TelegramBotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' Bot',
            'username' => Str::lower(Str::random(8)).'_bot',
            'token' => fake()->unique()->numberBetween(100000000, 999999999).':'.Str::random(35),
            'status' => BotStatus::Active,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (TelegramBot $bot) {
            $bot->connection_status ??= ConnectionStatus::Connected;
        });
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['status' => BotStatus::Disabled]);
    }
}
