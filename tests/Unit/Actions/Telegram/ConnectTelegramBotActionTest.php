<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\ConnectTelegramBotAction;
use App\Data\Telegram\ConnectTelegramBotData;
use App\Models\TelegramBotConfig;
use App\Models\Workspace;
use App\Support\Telegram\TelegramClientContract;
use App\Support\Telegram\TelegramGetMeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ConnectTelegramBotActionTest extends TestCase
{
    use RefreshDatabase;

    private function fakeClient(TelegramGetMeResult $result): TelegramClientContract
    {
        return new class($result) implements TelegramClientContract
        {
            public function __construct(private readonly TelegramGetMeResult $result) {}

            public function getMe(string $botToken): TelegramGetMeResult
            {
                return $this->result;
            }
        };
    }

    public function test_a_successful_check_connects_and_generates_a_secret_and_slug()
    {
        $workspace = Workspace::factory()->create();
        $client = $this->fakeClient(TelegramGetMeResult::success('harun_capture_bot'));

        $config = (new ConnectTelegramBotAction($client))->handle($workspace, new ConnectTelegramBotData('123:abc'));

        $this->assertTrue($config->isConnected());
        $this->assertSame('harun_capture_bot', $config->bot_username);
        $this->assertNotNull($config->webhook_secret);
        $this->assertNotNull($config->webhook_slug);
        $this->assertNotNull($config->connected_at);
    }

    public function test_reconnecting_keeps_the_same_webhook_secret_and_slug()
    {
        $workspace = Workspace::factory()->create();
        $existing = TelegramBotConfig::factory()->for($workspace)->connected()->create();
        $originalSecret = $existing->webhook_secret;
        $originalSlug = $existing->webhook_slug;

        $client = $this->fakeClient(TelegramGetMeResult::success('a_new_username'));
        $config = (new ConnectTelegramBotAction($client))->handle($workspace, new ConnectTelegramBotData('999:new-token'));

        $this->assertSame($originalSecret, $config->webhook_secret);
        $this->assertSame($originalSlug, $config->webhook_slug);
        $this->assertSame('a_new_username', $config->bot_username);
    }

    public function test_a_failed_check_throws_and_stores_nothing()
    {
        $workspace = Workspace::factory()->create();
        $client = $this->fakeClient(TelegramGetMeResult::failure('Telegram rejected this token as invalid.'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Telegram rejected this token as invalid.');

        try {
            (new ConnectTelegramBotAction($client))->handle($workspace, new ConnectTelegramBotData('bad'));
        } finally {
            $this->assertSame(0, TelegramBotConfig::where('workspace_id', $workspace->id)->count());
        }
    }
}
