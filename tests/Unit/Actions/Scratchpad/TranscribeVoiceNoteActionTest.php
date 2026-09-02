<?php

namespace Tests\Unit\Actions\Scratchpad;

use App\Actions\Scratchpad\TranscribeVoiceNoteAction;
use App\Jobs\GenerateTelegramPostJob;
use App\Models\AiProviderCredential;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Models\TelegramPostRequest;
use App\Models\Transcription;
use App\Models\Workspace;
use App\Support\AiProviders\AiProviderCredentialResolver;
use App\Support\AiProviders\AiTranscriptionClientContract;
use App\Support\AiProviders\AiTranscriptionResult;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class TranscribeVoiceNoteActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(TelegramClientContract::class, new FakeTelegramClient);
    }

    public function test_a_successful_transcription_updates_the_row_and_backfills_the_entry_language()
    {
        Storage::fake('scratchpad');
        Storage::disk('scratchpad')->put('audio/note.ogg', 'raw-bytes');

        $workspace = Workspace::factory()->create();
        $mediaAsset = MediaAsset::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'audio',
            'disk' => 'scratchpad',
            'path' => 'audio/note.ogg',
            'mime' => 'audio/ogg',
            'original_filename' => 'note.ogg',
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'voice',
            'source' => 'web',
            'language' => null,
        ]);
        $transcription = Transcription::factory()->create([
            'scratchpad_entry_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        AiProviderCredential::factory()->openai()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiTranscriptionClientContract
        {
            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                return AiTranscriptionResult::success(text: 'transcribed text', language: 'bengali');
            }
        };

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver))->handle($transcription);

        $transcription->refresh();
        $this->assertSame('done', $transcription->status);
        $this->assertSame('openai', $transcription->provider);
        $this->assertSame('whisper-1', $transcription->model);
        $this->assertSame('bengali', $transcription->language);
        $this->assertSame('transcribed text', $transcription->text);

        $entry->refresh();
        $this->assertSame('bengali', $entry->language);
    }

    public function test_it_does_not_overwrite_an_entry_language_that_was_already_set()
    {
        Storage::fake('scratchpad');
        Storage::disk('scratchpad')->put('audio/note.ogg', 'raw-bytes');

        $workspace = Workspace::factory()->create();
        $mediaAsset = MediaAsset::factory()->create([
            'workspace_id' => $workspace->id,
            'disk' => 'scratchpad',
            'path' => 'audio/note.ogg',
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'language' => 'en',
        ]);
        $transcription = Transcription::factory()->create([
            'scratchpad_entry_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        AiProviderCredential::factory()->openai()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiTranscriptionClientContract
        {
            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                return AiTranscriptionResult::success(text: 'transcribed text', language: 'bengali');
            }
        };

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver))->handle($transcription);

        $this->assertSame('en', $entry->refresh()->language);
    }

    public function test_a_telegram_sourced_entry_gets_a_reply_when_the_bot_is_connected_and_the_entry_has_a_stored_chat_id()
    {
        Storage::fake('scratchpad');
        Storage::disk('scratchpad')->put('audio/note.ogg', 'raw-bytes');

        $workspace = Workspace::factory()->create();
        $mediaAsset = MediaAsset::factory()->create([
            'workspace_id' => $workspace->id,
            'disk' => 'scratchpad',
            'path' => 'audio/note.ogg',
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'source' => 'telegram',
            'meta' => ['telegram_chat_id' => 987654321],
        ]);
        $transcription = Transcription::factory()->create([
            'scratchpad_entry_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        AiProviderCredential::factory()->openai()->create(['workspace_id' => $workspace->id]);
        $config = TelegramBotConfig::factory()->connected()->create([
            'workspace_id' => $workspace->id,
        ]);

        $client = new class implements AiTranscriptionClientContract
        {
            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                return AiTranscriptionResult::success(text: 'transcribed text', language: 'bn');
            }
        };
        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver))->handle($transcription);

        $message = TelegramOutboundMessage::query()->sole();
        $this->assertSame($config->bot_token, $message->telegramBotConfig?->bot_token);
        $this->assertSame(987654321, $message->chat_id);
        $this->assertSame('📝 Transcript: transcribed text', $message->chunks[0]);
    }

    public function test_a_successful_telegram_transcription_queues_post_generation(): void
    {
        Storage::fake('scratchpad');
        Storage::disk('scratchpad')->put('audio/note.ogg', 'raw-bytes');
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->connected()->create([
            'workspace_id' => $workspace->id,
        ]);
        $mediaAsset = MediaAsset::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'audio',
            'disk' => 'scratchpad',
            'path' => 'audio/note.ogg',
            'mime' => 'audio/ogg',
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'voice',
            'source' => 'telegram',
            'meta' => ['telegram_chat_id' => 555],
        ]);
        $transcription = Transcription::factory()->create([
            'scratchpad_entry_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $entry->id,
            'telegram_user_id' => 42,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::GENERATING,
        ]);
        AiProviderCredential::factory()->openai()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiTranscriptionClientContract
        {
            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                return AiTranscriptionResult::success(text: 'transcribed text', language: 'bn');
            }
        };

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver))->handle($transcription);

        Queue::assertPushed(GenerateTelegramPostJob::class, fn (GenerateTelegramPostJob $job): bool => $job->telegramPostRequestId === $request->id);
        $this->assertSame(TelegramPostRequest::GENERATING, $request->refresh()->state);
    }

    public function test_no_reply_is_sent_for_a_web_sourced_entry_even_with_a_connected_bot()
    {
        Storage::fake('scratchpad');
        Storage::disk('scratchpad')->put('audio/note.ogg', 'raw-bytes');

        $workspace = Workspace::factory()->create();
        $mediaAsset = MediaAsset::factory()->create([
            'workspace_id' => $workspace->id,
            'disk' => 'scratchpad',
            'path' => 'audio/note.ogg',
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'source' => 'web',
            'meta' => ['telegram_chat_id' => 987654321],
        ]);
        $transcription = Transcription::factory()->create([
            'scratchpad_entry_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        AiProviderCredential::factory()->openai()->create(['workspace_id' => $workspace->id]);
        TelegramBotConfig::factory()->connected()->create([
            'workspace_id' => $workspace->id,
        ]);

        $client = new class implements AiTranscriptionClientContract
        {
            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                return AiTranscriptionResult::success(text: 'transcribed text', language: 'bn');
            }
        };
        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver))->handle($transcription);

        $this->assertSame('done', $transcription->refresh()->status);
        $this->assertSame(0, TelegramOutboundMessage::count());
    }

    public function test_no_reply_is_sent_for_a_telegram_sourced_entry_with_no_stored_chat_id()
    {
        Storage::fake('scratchpad');
        Storage::disk('scratchpad')->put('audio/note.ogg', 'raw-bytes');

        $workspace = Workspace::factory()->create();
        $mediaAsset = MediaAsset::factory()->create([
            'workspace_id' => $workspace->id,
            'disk' => 'scratchpad',
            'path' => 'audio/note.ogg',
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'source' => 'telegram',
            'meta' => [],
        ]);
        $transcription = Transcription::factory()->create([
            'scratchpad_entry_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        AiProviderCredential::factory()->openai()->create(['workspace_id' => $workspace->id]);
        TelegramBotConfig::factory()->connected()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiTranscriptionClientContract
        {
            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                return AiTranscriptionResult::success(text: 'transcribed text', language: 'bn');
            }
        };
        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver))->handle($transcription);

        $this->assertSame('done', $transcription->refresh()->status);
        $this->assertSame(0, TelegramOutboundMessage::count());
    }

    public function test_no_reply_is_sent_when_the_workspace_has_no_connected_bot()
    {
        Storage::fake('scratchpad');
        Storage::disk('scratchpad')->put('audio/note.ogg', 'raw-bytes');

        $workspace = Workspace::factory()->create();
        $mediaAsset = MediaAsset::factory()->create([
            'workspace_id' => $workspace->id,
            'disk' => 'scratchpad',
            'path' => 'audio/note.ogg',
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'source' => 'telegram',
        ]);
        $transcription = Transcription::factory()->create([
            'scratchpad_entry_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        AiProviderCredential::factory()->openai()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiTranscriptionClientContract
        {
            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                return AiTranscriptionResult::success(text: 'transcribed text', language: 'bn');
            }
        };
        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver))->handle($transcription);

        $this->assertSame('done', $transcription->refresh()->status);
        $this->assertSame(0, TelegramOutboundMessage::count());
    }

    public function test_no_provider_configured_fails_honestly_without_touching_storage()
    {
        $workspace = Workspace::factory()->create();
        $mediaAsset = MediaAsset::factory()->create(['workspace_id' => $workspace->id]);
        $transcription = Transcription::factory()->create(['media_asset_id' => $mediaAsset->id]);

        $client = new class implements AiTranscriptionClientContract
        {
            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                throw new \RuntimeException('should never be called');
            }
        };

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver))->handle($transcription);

        $transcription->refresh();
        $this->assertSame('failed', $transcription->status);
        $this->assertSame('no_provider_configured', $transcription->error_code);
    }

    public function test_no_provider_configured_fails_a_telegram_post_request(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->connected()->create([
            'workspace_id' => $workspace->id,
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'source' => 'telegram',
        ]);
        $mediaAsset = MediaAsset::factory()->create(['workspace_id' => $workspace->id]);
        $transcription = Transcription::factory()->create([
            'scratchpad_entry_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $entry->id,
            'state' => TelegramPostRequest::GENERATING,
        ]);
        $client = new class implements AiTranscriptionClientContract
        {
            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                throw new \RuntimeException('should never be called');
            }
        };

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver))->handle($transcription);

        $this->assertSame(TelegramPostRequest::FAILED, $request->refresh()->state);
        $message = TelegramOutboundMessage::query()->sole();
        $this->assertStringContainsString('no OpenAI-shaped', $message->chunks[0]);
    }

    public function test_an_anthropic_only_workspace_is_treated_as_having_no_provider()
    {
        $workspace = Workspace::factory()->create();
        $mediaAsset = MediaAsset::factory()->create(['workspace_id' => $workspace->id]);
        $transcription = Transcription::factory()->create(['media_asset_id' => $mediaAsset->id]);
        AiProviderCredential::factory()->create(['workspace_id' => $workspace->id, 'provider' => 'anthropic']);

        $client = new class implements AiTranscriptionClientContract
        {
            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                throw new \RuntimeException('should never be called');
            }
        };

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver))->handle($transcription);

        $this->assertSame('no_provider_configured', $transcription->refresh()->error_code);
    }

    public function test_audio_missing_from_storage_fails_honestly()
    {
        Storage::fake('scratchpad');

        $workspace = Workspace::factory()->create();
        $mediaAsset = MediaAsset::factory()->create([
            'workspace_id' => $workspace->id,
            'disk' => 'scratchpad',
            'path' => 'audio/does-not-exist.ogg',
        ]);
        $transcription = Transcription::factory()->create(['media_asset_id' => $mediaAsset->id]);
        AiProviderCredential::factory()->openai()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiTranscriptionClientContract
        {
            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                throw new \RuntimeException('should never be called');
            }
        };

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver))->handle($transcription);

        $transcription->refresh();
        $this->assertSame('failed', $transcription->status);
        $this->assertSame('audio_missing', $transcription->error_code);
    }

    public function test_the_fallback_chain_tries_the_next_credential_after_a_failure()
    {
        Storage::fake('scratchpad');
        Storage::disk('scratchpad')->put('audio/note.ogg', 'raw-bytes');

        $workspace = Workspace::factory()->create();
        $mediaAsset = MediaAsset::factory()->create([
            'workspace_id' => $workspace->id,
            'disk' => 'scratchpad',
            'path' => 'audio/note.ogg',
        ]);
        $transcription = Transcription::factory()->create(['media_asset_id' => $mediaAsset->id]);
        $first = AiProviderCredential::factory()->openai()->create(['workspace_id' => $workspace->id, 'priority' => 0, 'api_key' => 'sk-first']);
        AiProviderCredential::factory()->openai()->create(['workspace_id' => $workspace->id, 'priority' => 1, 'api_key' => 'sk-second']);

        $client = new class implements AiTranscriptionClientContract
        {
            public array $attemptedKeys = [];

            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                $this->attemptedKeys[] = $credential->api_key;

                return $credential->api_key === 'sk-first'
                    ? AiTranscriptionResult::failure('first provider is down')
                    : AiTranscriptionResult::success(text: 'from the second provider', language: null);
            }
        };

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver))->handle($transcription);

        $this->assertSame(['sk-first', 'sk-second'], $client->attemptedKeys);
        $transcription->refresh();
        $this->assertSame('done', $transcription->status);
        $this->assertSame('from the second provider', $transcription->text);
    }

    public function test_exhausting_every_credential_fails_with_the_last_providers_error()
    {
        Storage::fake('scratchpad');
        Storage::disk('scratchpad')->put('audio/note.ogg', 'raw-bytes');

        $workspace = Workspace::factory()->create();
        $mediaAsset = MediaAsset::factory()->create([
            'workspace_id' => $workspace->id,
            'disk' => 'scratchpad',
            'path' => 'audio/note.ogg',
        ]);
        $transcription = Transcription::factory()->create(['media_asset_id' => $mediaAsset->id]);
        AiProviderCredential::factory()->openai()->create(['workspace_id' => $workspace->id, 'priority' => 0]);
        AiProviderCredential::factory()->openai()->create(['workspace_id' => $workspace->id, 'priority' => 1]);

        $client = new class implements AiTranscriptionClientContract
        {
            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                return AiTranscriptionResult::failure("provider {$credential->priority} is down");
            }
        };

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver))->handle($transcription);

        $transcription->refresh();
        $this->assertSame('failed', $transcription->status);
        $this->assertSame('transcription_failed', $transcription->error_code);
        $this->assertSame('provider 1 is down', $transcription->error_message);
    }
}
