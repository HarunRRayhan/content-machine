<?php

namespace Database\Factories;

use App\Models\TelegramBotConfig;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TelegramBotConfig>
 */
class TelegramBotConfigFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'bot_token' => null,
            'webhook_secret' => null,
            'webhook_slug' => null,
            'bot_username' => null,
            'connected_at' => null,
        ];
    }

    public function connected(): static
    {
        return $this->state(fn () => [
            'bot_token' => '123456:'.Str::random(35),
            'webhook_secret' => Str::random(40),
            'webhook_slug' => Str::random(40),
            'bot_username' => fake()->userName().'_bot',
            'connected_at' => now(),
        ]);
    }
}
