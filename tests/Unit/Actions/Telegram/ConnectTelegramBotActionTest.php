<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\ConnectTelegramBotAction;
use App\Data\Telegram\ConnectTelegramBotData;
use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Models\TelegramPostRequest;
use App\Models\TelegramUpdate;
use App\Models\Workspace;
use App\Support\Telegram\TelegramApiResult;
use App\Support\Telegram\TelegramGetMeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class ConnectTelegramBotActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_check_connects_and_generates_a_secret_and_slug()
    {
        $workspace = Workspace::factory()->create();
        $client = (new FakeTelegramClient)->willGetMe(TelegramGetMeResult::success('harun_capture_bot'));

        $config = (new ConnectTelegramBotAction($client))->handle($workspace, new ConnectTelegramBotData('123:abc'));

        $this->assertTrue($config->isConnected());
        $this->assertSame('harun_capture_bot', $config->bot_username);
        $this->assertNotNull($config->webhook_secret);
        $this->assertNotNull($config->webhook_slug);
        $this->assertNotNull($config->connected_at);
        $this->assertCount(1, $client->setMyCommandsCalledWith);
        $this->assertSame('123:abc', $client->setMyCommandsCalledWith[0]['botToken']);
    }

    public function test_rotating_the_bot_token_rotates_the_webhook_identity()
    {
        $workspace = Workspace::factory()->create();
        $existing = TelegramBotConfig::factory()->for($workspace)->connected()->create();
        $update = TelegramUpdate::create([
            'telegram_bot_config_id' => $existing->id,
            'webhook_generation' => $existing->webhook_generation,
            'update_id' => 123,
            'payload' => ['update_id' => 123],
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $existing->id,
            'state' => TelegramPostRequest::GENERATING,
            'webhook_generation' => $existing->webhook_generation,
        ]);
        $outbound = TelegramOutboundMessage::factory()->create([
            'telegram_bot_config_id' => $existing->id,
            'webhook_generation' => $existing->webhook_generation,
        ]);
        $originalToken = $existing->bot_token;
        $originalSecret = $existing->webhook_secret;
        $originalSlug = $existing->webhook_slug;
        $originalGeneration = $existing->webhook_generation;

        $client = (new FakeTelegramClient)->willGetMe(TelegramGetMeResult::success('a_new_username'));
        $config = (new ConnectTelegramBotAction($client))->handle($workspace, new ConnectTelegramBotData('999:new-token'));

        $this->assertNotSame($originalSecret, $config->webhook_secret);
        $this->assertNotSame($originalSlug, $config->webhook_slug);
        $this->assertNotSame($originalGeneration, $config->webhook_generation);
        $this->assertSame('a_new_username', $config->bot_username);
        $this->assertSame([$originalToken], $client->deleteWebhookCalledWith);
        $this->assertNotNull($update->refresh()->discarded_at);
        $this->assertSame(TelegramPostRequest::CANCELLED, $request->refresh()->state);
        $this->assertSame(TelegramOutboundMessage::DISCARDED, $outbound->refresh()->status);
    }

    public function test_a_failed_getme_check_throws_and_stores_nothing()
    {
        $workspace = Workspace::factory()->create();
        $client = (new FakeTelegramClient)->willGetMe(TelegramGetMeResult::failure('Telegram rejected this token as invalid.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Telegram rejected this token as invalid.');

        try {
            (new ConnectTelegramBotAction($client))->handle($workspace, new ConnectTelegramBotData('bad'));
        } finally {
            $this->assertSame(0, TelegramBotConfig::where('workspace_id', $workspace->id)->count());
        }
    }

    public function test_a_failed_webhook_registration_throws_and_stores_nothing()
    {
        $workspace = Workspace::factory()->create();
        $client = (new FakeTelegramClient)
            ->willGetMe(TelegramGetMeResult::success('harun_capture_bot'))
            ->willSetWebhook(TelegramApiResult::failure('Telegram rejected the webhook registration.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Telegram rejected the webhook registration.');

        try {
            (new ConnectTelegramBotAction($client))->handle($workspace, new ConnectTelegramBotData('123:abc'));
        } finally {
            $this->assertSame(0, TelegramBotConfig::where('workspace_id', $workspace->id)->count());
        }
    }
}
