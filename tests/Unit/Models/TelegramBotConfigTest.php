<?php

namespace Tests\Unit\Models;

use App\Models\TelegramBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TelegramBotConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_connected_reflects_whether_a_token_is_present()
    {
        $disconnected = TelegramBotConfig::factory()->create();
        $connected = TelegramBotConfig::factory()->connected()->create();

        $this->assertFalse($disconnected->isConnected());
        $this->assertTrue($connected->isConnected());
    }

    public function test_the_bot_token_and_webhook_secret_are_encrypted_at_rest()
    {
        $config = TelegramBotConfig::factory()->create([
            'bot_token' => '123456:plaintext-token',
            'webhook_secret' => 'plaintext-secret',
        ]);

        $raw = DB::table('telegram_bot_configs')->where('id', $config->id)->first();

        $this->assertNotSame('123456:plaintext-token', $raw->bot_token);
        $this->assertNotSame('plaintext-secret', $raw->webhook_secret);
        $this->assertSame('123456:plaintext-token', $config->fresh()->bot_token);
        $this->assertSame('plaintext-secret', $config->fresh()->webhook_secret);
    }
}
