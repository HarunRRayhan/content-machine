<?php

namespace Database\Factories;

use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramBotLink>
 */
class TelegramBotLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_bot_config_id' => TelegramBotConfig::factory(),
            'user_id' => User::factory(),
            'telegram_user_id' => fake()->unique()->numberBetween(100000, 999999999),
            'telegram_username' => fake()->userName(),
            'linked_at' => now(),
        ];
    }
}
