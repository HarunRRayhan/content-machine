<?php

namespace Tests\Unit\Jobs;

use App\Actions\Scratchpad\CaptureScratchpadLinkAction;
use App\Actions\Scratchpad\CaptureScratchpadPhotoAction;
use App\Actions\Scratchpad\CaptureScratchpadVoiceAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Actions\Telegram\CaptureTelegramMessageAction;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class ProcessTelegramUpdateJobTest extends TestCase
{
    use RefreshDatabase;

    private function action(): CaptureTelegramMessageAction
    {
        return new CaptureTelegramMessageAction(
            new CaptureTextNoteAction,
            new CaptureScratchpadLinkAction,
            new CaptureScratchpadPhotoAction,
            new CaptureScratchpadVoiceAction,
            new FakeTelegramClient,
        );
    }

    public function test_it_delegates_to_the_capture_action_for_a_connected_config()
    {
        $config = TelegramBotConfig::factory()->connected()->create();

        $update = [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 1],
                'from' => ['id' => 1],
                'text' => 'A captured note.',
            ],
        ];

        (new ProcessTelegramUpdateJob($config->id, $update))->handle($this->action());

        $this->assertSame(1, ScratchpadEntry::count());
    }

    public function test_it_is_a_no_op_when_the_config_no_longer_exists()
    {
        (new ProcessTelegramUpdateJob(999999, ['update_id' => 1]))->handle($this->action());

        $this->assertSame(0, ScratchpadEntry::count());
    }

    public function test_it_is_a_no_op_when_the_bot_has_since_been_disconnected()
    {
        $config = TelegramBotConfig::factory()->create();

        $update = [
            'update_id' => 1,
            'message' => ['chat' => ['id' => 1], 'from' => ['id' => 1], 'text' => 'hi'],
        ];

        (new ProcessTelegramUpdateJob($config->id, $update))->handle($this->action());

        $this->assertSame(0, ScratchpadEntry::count());
    }
}
