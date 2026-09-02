<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\DisconnectTelegramBotAction;
use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Models\TelegramPostRequest;
use App\Models\TelegramUpdate;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class DisconnectTelegramBotActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_clears_the_token_and_username_but_keeps_the_webhook_identity()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $secret = $config->webhook_secret;
        $slug = $config->webhook_slug;

        (new DisconnectTelegramBotAction(new FakeTelegramClient))->handle($config);
        $fresh = $config->fresh();

        $this->assertFalse($fresh->isConnected());
        $this->assertNull($fresh->bot_username);
        $this->assertNull($fresh->connected_at);
        $this->assertSame($secret, $fresh->webhook_secret);
        $this->assertSame($slug, $fresh->webhook_slug);
    }

    public function test_it_tells_telegram_to_remove_the_webhook_using_the_still_present_token()
    {
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:the-token']);
        $client = new FakeTelegramClient;

        (new DisconnectTelegramBotAction($client))->handle($config);

        $this->assertSame(['123:the-token'], $client->deleteWebhookCalledWith);
    }

    public function test_it_discards_pending_telegram_work_before_invalidating_the_generation(): void
    {
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->for($workspace)->connected()->create();
        $generation = $config->webhook_generation;
        $update = TelegramUpdate::create([
            'telegram_bot_config_id' => $config->id,
            'update_id' => 123,
            'webhook_generation' => $generation,
            'payload' => ['update_id' => 123],
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'state' => TelegramPostRequest::GENERATING,
            'webhook_generation' => $generation,
            'work_claimed_at' => now(),
            'work_lease_id' => '72d9c4a1-58b0-4be7-95c0-a1d2227d2f22',
        ]);
        $outbound = TelegramOutboundMessage::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $generation,
        ]);

        (new DisconnectTelegramBotAction(new FakeTelegramClient))->handle($config);

        $this->assertNotSame($generation, $config->refresh()->webhook_generation);
        $this->assertNotNull($update->refresh()->discarded_at);
        $this->assertSame(TelegramPostRequest::CANCELLED, $request->refresh()->state);
        $this->assertSame('72d9c4a1-58b0-4be7-95c0-a1d2227d2f22', $request->work_lease_id);
        $this->assertSame(TelegramOutboundMessage::DISCARDED, $outbound->refresh()->status);
    }

    public function test_it_does_not_call_telegram_when_already_disconnected()
    {
        $config = TelegramBotConfig::factory()->create();
        $client = new FakeTelegramClient;

        (new DisconnectTelegramBotAction($client))->handle($config);

        $this->assertSame([], $client->deleteWebhookCalledWith);
    }
}
