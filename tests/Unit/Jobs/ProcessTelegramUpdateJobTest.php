<?php

namespace Tests\Unit\Jobs;

use App\Actions\Scratchpad\CaptureScratchpadLinkAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Actions\Telegram\CaptureTelegramMessageAction;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Support\Telegram\TelegramApiResult;
use App\Support\Telegram\TelegramClientContract;
use App\Support\Telegram\TelegramGetMeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessTelegramUpdateJobTest extends TestCase
{
    use RefreshDatabase;

    private function fakeClient(): TelegramClientContract
    {
        return new class implements TelegramClientContract
        {
            public function getMe(string $botToken): TelegramGetMeResult
            {
                return TelegramGetMeResult::success('irrelevant');
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
        };
    }

    public function test_it_delegates_to_the_capture_action_for_a_connected_config()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $action = new CaptureTelegramMessageAction(
            new CaptureTextNoteAction,
            new CaptureScratchpadLinkAction,
            $this->fakeClient(),
        );

        $update = [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 1],
                'from' => ['id' => 1],
                'text' => 'A captured note.',
            ],
        ];

        (new ProcessTelegramUpdateJob($config->id, $update))->handle($action);

        $this->assertSame(1, ScratchpadEntry::count());
    }

    public function test_it_is_a_no_op_when_the_config_no_longer_exists()
    {
        $action = new CaptureTelegramMessageAction(
            new CaptureTextNoteAction,
            new CaptureScratchpadLinkAction,
            $this->fakeClient(),
        );

        (new ProcessTelegramUpdateJob(999999, ['update_id' => 1]))->handle($action);

        $this->assertSame(0, ScratchpadEntry::count());
    }

    public function test_it_is_a_no_op_when_the_bot_has_since_been_disconnected()
    {
        $config = TelegramBotConfig::factory()->create();
        $action = new CaptureTelegramMessageAction(
            new CaptureTextNoteAction,
            new CaptureScratchpadLinkAction,
            $this->fakeClient(),
        );

        $update = [
            'update_id' => 1,
            'message' => ['chat' => ['id' => 1], 'from' => ['id' => 1], 'text' => 'hi'],
        ];

        (new ProcessTelegramUpdateJob($config->id, $update))->handle($action);

        $this->assertSame(0, ScratchpadEntry::count());
    }
}
