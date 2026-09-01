<?php

namespace Database\Factories;

use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramPostRequest>
 */
class TelegramPostRequestFactory extends Factory
{
    protected $model = TelegramPostRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'telegram_bot_config_id' => TelegramBotConfig::factory(),
            'telegram_user_id' => fake()->numberBetween(100000, 999999999),
            'telegram_chat_id' => fake()->numberBetween(100000, 999999999),
            'state' => TelegramPostRequest::AWAITING_INPUT,
        ];
    }
}
