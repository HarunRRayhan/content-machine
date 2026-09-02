<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Scratchpad\CaptureScratchpadLinkAction;
use App\Actions\Scratchpad\CaptureScratchpadPhotoAction;
use App\Actions\Scratchpad\CaptureScratchpadVoiceAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Actions\Telegram\CaptureTelegramMessageAction;
use App\Models\Attachment;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Support\Telegram\TelegramClientContract;
use App\Support\Telegram\TelegramFileDownloadResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class CaptureTelegramMessageActionTest extends TestCase
{
    use RefreshDatabase;

    private FakeTelegramClient $client;

    private function action(): CaptureTelegramMessageAction
    {
        $this->client = new FakeTelegramClient;
        $this->app->instance(TelegramClientContract::class, $this->client);

        return new CaptureTelegramMessageAction(
            new CaptureTextNoteAction,
            new CaptureScratchpadLinkAction,
            new CaptureScratchpadPhotoAction,
            new CaptureScratchpadVoiceAction,
            $this->client,
        );
    }

    private function textUpdate(int $fromId, string $text, int $chatId = 555): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $fromId],
                'text' => $text,
            ],
        ];
    }

    /**
     * Outbox delivery is deferred until the test transaction commits.
     *
     * @return list<array{botToken: string|null, chatId: int, text: string}>
     */
    private function outboundMessages(): array
    {
        $messages = [];

        foreach (TelegramOutboundMessage::query()->with('telegramBotConfig')->orderBy('id')->get() as $message) {
            $chunk = $message->chunks[0] ?? null;
            if (! is_string($chunk)) {
                continue;
            }

            $messages[] = [
                'botToken' => $message->telegramBotConfig?->bot_token,
                'chatId' => $message->chat_id,
                'text' => $chunk,
            ];
        }

        return $messages;
    }

    public function test_a_text_message_is_captured_as_a_note()
    {
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);

        $this->action()->handle($config, $this->textUpdate(42, 'A thought worth keeping.'));

        $entry = ScratchpadEntry::sole();
        $this->assertSame('text', $entry->kind);
        $this->assertSame('telegram', $entry->source);
        $this->assertSame('A thought worth keeping.', $entry->body);
        $this->assertSame([['botToken' => '123:tok', 'chatId' => 555, 'text' => 'Captured.']], $this->outboundMessages());
    }

    public function test_a_non_private_chat_is_ignored(): void
    {
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);
        $update = $this->textUpdate(42, 'A group message.');
        $update['message']['chat']['type'] = 'group';

        $this->assertNull($this->action()->handle($config, $update));
        $this->assertSame(0, ScratchpadEntry::count());
        $this->assertSame([], $this->outboundMessages());
    }

    public function test_a_message_that_is_only_a_url_is_captured_as_a_link()
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);

        $this->action()->handle($config, $this->textUpdate(42, 'https://example.com/article'));

        $entry = ScratchpadEntry::sole();
        $this->assertSame('link', $entry->kind);
        $this->assertSame('telegram', $entry->source);
        $this->assertSame('https://example.com/article', $entry->meta['url']);
        $this->assertSame([['botToken' => '123:tok', 'chatId' => 555, 'text' => '🔗 Link captured.']], $this->outboundMessages());
    }

    public function test_a_message_with_none_of_the_supported_content_gets_an_honest_not_yet_reply()
    {
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);

        $update = [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 42],
                'document' => ['file_id' => 'abc'],
            ],
        ];

        $this->action()->handle($config, $update);

        $this->assertSame(0, ScratchpadEntry::count());
        $messages = $this->outboundMessages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('text, links, photos, voice notes, and audio files', $messages[0]['text']);
    }

    public function test_an_update_with_no_message_key_is_ignored()
    {
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);

        $this->action()->handle($config, ['update_id' => 1, 'edited_message' => ['text' => 'edited']]);

        $this->assertSame(0, ScratchpadEntry::count());
        $this->assertSame([], $this->outboundMessages());
    }

    public function test_a_photo_is_downloaded_and_captured_with_its_caption()
    {
        Storage::fake('scratchpad');
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);

        // Held in a variable rather than read inline: Illuminate\Http\
        // Testing\File cleans up its own temp file once nothing references
        // it, which can happen before an inline file_get_contents() runs.
        $fakeImage = UploadedFile::fake()->image('x.jpg', 20, 10);
        $realJpegBytes = file_get_contents($fakeImage->getRealPath());
        $action = $this->action();
        $this->client->willDownloadFile(TelegramFileDownloadResult::success($realJpegBytes));

        $update = [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 42],
                'caption' => 'From the roof',
                // Telegram lists PhotoSize entries smallest to largest; the
                // action must pick the last one.
                'photo' => [
                    ['file_id' => 'small', 'width' => 90, 'height' => 51],
                    ['file_id' => 'large', 'width' => 800, 'height' => 450],
                ],
            ],
        ];

        $action->handle($config, $update);

        $entry = ScratchpadEntry::sole();
        $this->assertSame('photo', $entry->kind);
        $this->assertSame('telegram', $entry->source);
        $this->assertSame('From the roof', $entry->body);
        $this->assertSame(1, Attachment::count());
        $this->assertSame([['botToken' => '123:tok', 'chatId' => 555, 'text' => '📷 Photo captured.']], $this->outboundMessages());
    }

    public function test_a_photo_download_failure_replies_honestly_and_captures_nothing()
    {
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);
        $action = $this->action();
        $this->client->willDownloadFile(TelegramFileDownloadResult::failure('file is too big'));

        $update = [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 42],
                'photo' => [['file_id' => 'x', 'width' => 10, 'height' => 10]],
            ],
        ];

        $action->handle($config, $update);

        $this->assertSame(0, ScratchpadEntry::count());
        $this->assertStringContainsString('file is too big', $this->outboundMessages()[0]['text']);
    }

    public function test_a_voice_note_is_downloaded_and_captured()
    {
        Storage::fake('scratchpad');
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);
        $action = $this->action();
        $this->client->willDownloadFile(TelegramFileDownloadResult::success('not-really-audio-but-thats-fine'));

        $update = [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 42],
                'voice' => ['file_id' => 'v1', 'duration' => 4, 'mime_type' => 'audio/ogg'],
            ],
        ];

        $action->handle($config, $update);

        $entry = ScratchpadEntry::sole();
        $this->assertSame('voice', $entry->kind);
        $this->assertSame('telegram', $entry->source);
        $this->assertSame(555, $entry->meta['telegram_chat_id']);
        $mediaAsset = Attachment::sole()->mediaAsset;
        $this->assertSame('audio/ogg', $mediaAsset->mime);
        $this->assertSame([['botToken' => '123:tok', 'chatId' => 555, 'text' => '🎙️ Voice note captured.']], $this->outboundMessages());
    }

    public function test_an_audio_file_is_captured_as_voice_with_its_caption()
    {
        Storage::fake('scratchpad');
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);
        $action = $this->action();
        $this->client->willDownloadFile(TelegramFileDownloadResult::success('not-really-audio-but-thats-fine'));

        $update = [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 42],
                'caption' => 'Turn this recording into a post later',
                'audio' => [
                    'file_id' => 'audio-id',
                    'duration' => 4,
                    'mime_type' => 'audio/mpeg',
                    'file_name' => 'recording.mp3',
                ],
            ],
        ];

        $action->handle($config, $update);

        $entry = ScratchpadEntry::sole();
        $this->assertSame('voice', $entry->kind);
        $this->assertSame('Turn this recording into a post later', $entry->body);
        $this->assertSame('recording.mp3', Attachment::sole()->mediaAsset->original_filename);
        $this->assertSame('audio/mpeg', Attachment::sole()->mediaAsset->mime);
    }

    public function test_a_voice_download_failure_replies_honestly_and_captures_nothing()
    {
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);
        $action = $this->action();
        $this->client->willDownloadFile(TelegramFileDownloadResult::failure('Telegram could not find that file.'));

        $update = [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 42],
                'voice' => ['file_id' => 'v1', 'duration' => 4, 'mime_type' => 'audio/ogg'],
            ],
        ];

        $action->handle($config, $update);

        $this->assertSame(0, ScratchpadEntry::count());
        $this->assertStringContainsString('Telegram could not find that file.', $this->outboundMessages()[0]['text']);
    }
}
