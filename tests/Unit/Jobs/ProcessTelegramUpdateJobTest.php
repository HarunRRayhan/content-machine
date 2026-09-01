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
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Support\AiProviders\AiCompletionClientContract;
use App\Support\AiProviders\AiCompletionResult;
use App\Support\AiProviders\AiProviderCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_audio_updates_use_the_scratchpad_queue(): void
    {
        $job = new ProcessTelegramUpdateJob(1, [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 1],
                'from' => ['id' => 1],
                'audio' => ['file_id' => 'audio-id'],
            ],
        ]);

        $this->assertSame('scratchpad', $job->queue);
    }

    public function test_a_legacy_serialized_job_defaults_the_webhook_generation(): void
    {
        $job = new ProcessTelegramUpdateJob(123, ['update_id' => 1]);
        $legacyJob = unserialize(serialize($job));

        $this->assertInstanceOf(ProcessTelegramUpdateJob::class, $legacyJob);
        $this->assertNull($legacyJob->webhookGeneration);
        $this->assertSame('telegram-update:123:legacy:1', $legacyJob->uniqueId());
    }

    public function test_a_completed_update_is_not_processed_again(): void
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'telegram_user_id' => 1,
        ]);
        $payload = [
            'update_id' => 42,
            'message' => [
                'chat' => ['id' => 1],
                'from' => ['id' => 1],
                'text' => 'Process once.',
            ],
        ];
        TelegramUpdate::create([
            'telegram_bot_config_id' => $config->id,
            'update_id' => 42,
            'payload' => $payload,
        ]);

        $job = new ProcessTelegramUpdateJob($config->id, $payload);
        $action = $this->action();
        $job->handle($action);
        $job->handle($action);

        $this->assertSame(1, ScratchpadEntry::count());
        $this->assertNotNull(TelegramUpdate::query()->sole()->processed_at);
    }

    public function test_clearnotes_is_replay_safe_when_processing_restarts_after_the_delete(): void
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'telegram_user_id' => 1,
        ]);
        ScratchpadEntry::factory()->for($config->workspace)->count(3)->create();
        $payload = [
            'update_id' => 43,
            'message' => [
                'chat' => ['id' => 1],
                'from' => ['id' => 1],
                'text' => '/clearnotes',
            ],
        ];
        TelegramUpdate::create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $config->webhook_generation,
            'update_id' => 43,
            'payload' => $payload,
        ]);

        $job = new ProcessTelegramUpdateJob($config->id, $payload, $config->webhook_generation);
        $job->handle($this->action());
        $job->handle($this->action());

        $this->assertSame(0, ScratchpadEntry::count());
        $this->assertNotNull(TelegramUpdate::query()->sole()->processed_at);
    }

    public function test_an_old_webhook_generation_is_discarded_without_running_the_update(): void
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'telegram_user_id' => 1,
        ]);
        $oldGeneration = $config->webhook_generation;
        $payload = [
            'update_id' => 55,
            'message' => [
                'chat' => ['id' => 1],
                'from' => ['id' => 1],
                'text' => 'stale update',
            ],
        ];
        $record = TelegramUpdate::create([
            'telegram_bot_config_id' => $config->id,
            'webhook_generation' => $oldGeneration,
            'update_id' => 55,
            'payload' => $payload,
        ]);
        $config->update(['webhook_generation' => (string) Str::uuid()]);

        (new ProcessTelegramUpdateJob($config->id, $payload, $oldGeneration))->handle($this->action());

        $this->assertSame(0, ScratchpadEntry::count());
        $this->assertNotNull($record->refresh()->processed_at);
    }
}
