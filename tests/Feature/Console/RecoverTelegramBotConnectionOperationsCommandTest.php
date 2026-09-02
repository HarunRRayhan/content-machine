<?php

namespace Tests\Feature\Console;

use App\Actions\Telegram\ConnectTelegramBotAction;
use App\Actions\Telegram\DisconnectTelegramBotAction;
use App\Data\Telegram\ConnectTelegramBotData;
use App\Models\TelegramBotConfig;
use App\Models\Workspace;
use App\Support\Telegram\TelegramApiResult;
use App\Support\Telegram\TelegramClientContract;
use App\Support\Telegram\TelegramGetMeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class RecoverTelegramBotConnectionOperationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recovers_a_connect_that_lost_the_external_result(): void
    {
        $workspace = Workspace::factory()->create();
        $client = (new FakeTelegramClient)
            ->willGetMe(TelegramGetMeResult::success('capture_bot'))
            ->willSetWebhook(TelegramApiResult::failure(
                'Telegram did not confirm the webhook registration.',
                outcomeUnknown: true,
            ));
        $this->app->instance(TelegramClientContract::class, $client);

        $thrown = false;
        try {
            (new ConnectTelegramBotAction($client))->handle(
                $workspace,
                new ConnectTelegramBotData('123:token'),
            );
        } catch (\RuntimeException $exception) {
            $this->assertSame('Telegram did not confirm the webhook registration.', $exception->getMessage());
            $thrown = true;
        }
        $this->assertTrue($thrown);

        $pending = TelegramBotConfig::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(TelegramBotConfig::CONNECTING, $pending->connection_operation);
        $this->assertSame('123:token', $pending->connection_operation_token);

        $client->willSetWebhook(TelegramApiResult::success());

        $this->artisan('telegram:recover-connection-operations')
            ->assertSuccessful()
            ->expectsOutput('Recovered 1 Telegram connection operation(s).');

        $this->assertTrue($pending->fresh()->isConnected());
        $this->assertNull($pending->fresh()->connection_operation);
    }

    public function test_it_recovers_a_disconnect_after_remote_removal_fails(): void
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $oldToken = $config->bot_token;
        $client = (new FakeTelegramClient)->willDeleteWebhook(TelegramApiResult::failure(
            'Telegram did not confirm the webhook removal.',
        ));
        $this->app->instance(TelegramClientContract::class, $client);

        (new DisconnectTelegramBotAction($client))->handle($config);

        $pending = $config->fresh();
        $this->assertFalse($pending->isConnected());
        $this->assertSame(TelegramBotConfig::DISCONNECTING, $pending->connection_operation);
        $this->assertSame($oldToken, $pending->connection_operation_token);

        $client->willDeleteWebhook(TelegramApiResult::success());

        $this->artisan('telegram:recover-connection-operations')
            ->assertSuccessful()
            ->expectsOutput('Recovered 1 Telegram connection operation(s).');

        $this->assertNull($pending->fresh()->connection_operation);
    }
}
