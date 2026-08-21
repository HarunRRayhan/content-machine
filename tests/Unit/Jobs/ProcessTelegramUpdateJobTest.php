<?php

namespace Tests\Unit\Jobs;

use App\Actions\Scratchpad\CaptureScratchpadLinkAction;
use App\Actions\Scratchpad\CaptureScratchpadPhotoAction;
use App\Actions\Scratchpad\CaptureScratchpadVoiceAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Actions\Scratchpad\DeleteRecentScratchpadEntriesAction;
use App\Actions\Scratchpad\DeleteScratchpadEntryAction;
use App\Actions\Telegram\CaptureTelegramMessageAction;
use App\Actions\Telegram\GenerateTelegramChatReplyAction;
use App\Actions\Telegram\HandleTelegramUpdateAction;
use App\Actions\Telegram\LinkTelegramAccountAction;
use App\Actions\Telegram\ResolveTelegramIntentAction;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\User;
use App\Support\AiProviders\AiCompletionClientContract;
use App\Support\AiProviders\AiCompletionResult;
use App\Support\AiProviders\AiProviderCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class ProcessTelegramUpdateJobTest extends TestCase
{
    use RefreshDatabase;

    private function action(): HandleTelegramUpdateAction
    {
        $client = new FakeTelegramClient;
        $completionClient = new class implements AiCompletionClientContract
        {
            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                throw new RuntimeException('AI chat is disabled in this test and should never be called.');
            }
        };

        return new HandleTelegramUpdateAction(
            new CaptureTelegramMessageAction(
                new CaptureTextNoteAction,
                new CaptureScratchpadLinkAction,
                new CaptureScratchpadPhotoAction,
                new CaptureScratchpadVoiceAction,
                $client,
            ),
            new CaptureTextNoteAction,
            new LinkTelegramAccountAction,
            new GenerateTelegramChatReplyAction($completionClient, new AiProviderCredentialResolver),
            new ResolveTelegramIntentAction($completionClient, new AiProviderCredentialResolver),
            new DeleteRecentScratchpadEntriesAction(new DeleteScratchpadEntryAction),
            $client,
        );
    }

    public function test_it_delegates_to_the_capture_action_for_a_linked_sender()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);

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
