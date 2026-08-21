<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\SyncTelegramBotCommandsAction;
use App\Models\TelegramBotConfig;
use App\Support\Telegram\TelegramApiResult;
use App\Support\Telegram\TelegramBotCommands;
use App\Support\Telegram\TelegramClientContract;
use App\Support\Telegram\TelegramFileDownloadResult;
use App\Support\Telegram\TelegramGetMeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class SyncTelegramBotCommandsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_commands_for_every_connected_bot_and_skips_disconnected_ones()
    {
        $client = new FakeTelegramClient;
        TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:abc']);
        TelegramBotConfig::factory()->create();

        (new SyncTelegramBotCommandsAction($client))->handle();

        $this->assertSame(1, count($client->setMyCommandsCalledWith));
        $this->assertSame('123:abc', $client->setMyCommandsCalledWith[0]['botToken']);
        $this->assertSame(TelegramBotCommands::LIST, $client->setMyCommandsCalledWith[0]['commands']);
    }

    public function test_a_failure_for_one_bot_does_not_stop_the_rest()
    {
        $failing = new class implements TelegramClientContract
        {
            /**
             * @var list<string>
             */
            public array $calledWith = [];

            public function getMe(string $botToken): TelegramGetMeResult
            {
                return TelegramGetMeResult::success('fake_bot');
            }

            public function setWebhook(string $botToken, string $url, string $secretToken): TelegramApiResult
            {
                return TelegramApiResult::success();
            }

            public function deleteWebhook(string $botToken): TelegramApiResult
            {
                return TelegramApiResult::success();
            }

            public function sendMessage(string $botToken, int $chatId, string $text): TelegramApiResult
            {
                return TelegramApiResult::success();
            }

            public function setMyCommands(string $botToken, array $commands): TelegramApiResult
            {
                if ($botToken === 'bad:token') {
                    throw new RuntimeException('unreachable');
                }

                $this->calledWith[] = $botToken;

                return TelegramApiResult::success();
            }

            public function downloadFile(string $botToken, string $fileId): TelegramFileDownloadResult
            {
                return TelegramFileDownloadResult::failure('not used in this test');
            }
        };

        TelegramBotConfig::factory()->connected()->create(['bot_token' => 'bad:token']);
        TelegramBotConfig::factory()->connected()->create(['bot_token' => 'good:token']);

        (new SyncTelegramBotCommandsAction($failing))->handle();

        $this->assertSame(['good:token'], $failing->calledWith);
    }
}
