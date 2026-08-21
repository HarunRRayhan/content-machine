<?php

namespace Database\Factories;

use App\Models\TelegramBotConfig;
use App\Models\TelegramLinkCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TelegramLinkCode>
 */
class TelegramLinkCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_bot_config_id' => TelegramBotConfig::factory(),
            'user_id' => User::factory(),
            'code' => strtoupper(Str::random(8)),
            'expires_at' => now()->addMinutes(15),
            'consumed_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    public function consumed(): static
    {
        return $this->state(fn () => ['consumed_at' => now()]);
    }
}
