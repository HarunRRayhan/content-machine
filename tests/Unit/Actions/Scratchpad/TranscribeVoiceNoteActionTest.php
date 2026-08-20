<?php

namespace Tests\Unit\Actions\Scratchpad;

use App\Actions\Scratchpad\TranscribeVoiceNoteAction;
use App\Models\AiProviderCredential;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\Transcription;
use App\Models\Workspace;
use App\Support\AiProviders\AiProviderCredentialResolver;
use App\Support\AiProviders\AiTranscriptionClientContract;
use App\Support\AiProviders\AiTranscriptionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class TranscribeVoiceNoteActionTest extends TestCase
{
    use RefreshDatabase;

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

        $telegram = new FakeTelegramClient;

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver, $telegram))->handle($transcription);

        $transcription->refresh();
        $this->assertSame('done', $transcription->status);
        $this->assertSame('openai', $transcription->provider);
        $this->assertSame('whisper-1', $transcription->model);
        $this->assertSame('bengali', $transcription->language);
        $this->assertSame('transcribed text', $transcription->text);

        $entry->refresh();
        $this->assertSame('bengali', $entry->language);
        $this->assertSame([], $telegram->sentMessages);
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

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver, new FakeTelegramClient))->handle($transcription);

        $this->assertSame('en', $entry->refresh()->language);
    }

    public function test_a_telegram_sourced_entry_gets_a_reply_when_the_bot_is_connected_and_linked()
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
        $config = TelegramBotConfig::factory()->connected()->create([
            'workspace_id' => $workspace->id,
            'linked_telegram_user_id' => 987654321,
        ]);

        $client = new class implements AiTranscriptionClientContract
        {
            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                return AiTranscriptionResult::success(text: 'transcribed text', language: 'bn');
            }
        };
        $telegram = new FakeTelegramClient;

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver, $telegram))->handle($transcription);

        $this->assertCount(1, $telegram->sentMessages);
        $this->assertSame($config->bot_token, $telegram->sentMessages[0]['botToken']);
        $this->assertSame(987654321, $telegram->sentMessages[0]['chatId']);
        $this->assertSame('📝 Transcript: transcribed text', $telegram->sentMessages[0]['text']);
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
        ]);
        $transcription = Transcription::factory()->create([
            'scratchpad_entry_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        AiProviderCredential::factory()->openai()->create(['workspace_id' => $workspace->id]);
        TelegramBotConfig::factory()->connected()->create([
            'workspace_id' => $workspace->id,
            'linked_telegram_user_id' => 987654321,
        ]);

        $client = new class implements AiTranscriptionClientContract
        {
            public function transcribe($credential, $audioContents, $filename, $mimeType): AiTranscriptionResult
            {
                return AiTranscriptionResult::success(text: 'transcribed text', language: 'bn');
            }
        };
        $telegram = new FakeTelegramClient;

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver, $telegram))->handle($transcription);

        $this->assertSame([], $telegram->sentMessages);
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
        $telegram = new FakeTelegramClient;

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver, $telegram))->handle($transcription);

        $this->assertSame([], $telegram->sentMessages);
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

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver, new FakeTelegramClient))->handle($transcription);

        $transcription->refresh();
        $this->assertSame('failed', $transcription->status);
        $this->assertSame('no_provider_configured', $transcription->error_code);
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

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver, new FakeTelegramClient))->handle($transcription);

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

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver, new FakeTelegramClient))->handle($transcription);

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

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver, new FakeTelegramClient))->handle($transcription);

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

        (new TranscribeVoiceNoteAction($client, new AiProviderCredentialResolver, new FakeTelegramClient))->handle($transcription);

        $transcription->refresh();
        $this->assertSame('failed', $transcription->status);
        $this->assertSame('transcription_failed', $transcription->error_code);
        $this->assertSame('provider 1 is down', $transcription->error_message);
    }
}
