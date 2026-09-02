<?php

namespace Database\Factories;

use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramOutboundMessage>
 */
class TelegramOutboundMessageFactory extends Factory
{
    protected $model = TelegramOutboundMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_bot_config_id' => TelegramBotConfig::factory()->connected(),
            'webhook_generation' => fn (array $attributes): ?string => TelegramBotConfig::query()
                ->whereKey($attributes['telegram_bot_config_id'])
                ->value('webhook_generation'),
            'chat_id' => 555,
            'logical_key' => 'telegram:test:'.$this->faker->uuid(),
            'chunks' => ['Test message.'],
            'next_chunk' => 0,
            'attempts' => 0,
            'status' => TelegramOutboundMessage::PENDING,
            'next_attempt_at' => null,
            'dispatch_claimed_at' => null,
            'dispatch_lease_id' => null,
            'last_attempt_at' => null,
            'sent_at' => null,
            'failed_at' => null,
            'discarded_at' => null,
        ];
    }
}
