<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Scratchpad\CaptureScratchpadLinkAction;
use App\Actions\Scratchpad\CaptureScratchpadPhotoAction;
use App\Actions\Scratchpad\CaptureScratchpadVoiceAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Actions\Telegram\CaptureTelegramMessageAction;
use App\Actions\Telegram\StartTelegramPostAction;
use App\Jobs\GenerateTelegramPostJob;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramPostRequest;
use App\Models\User;
use App\Support\Telegram\TelegramFileDownloadResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class StartTelegramPostActionTest extends TestCase
{
    use RefreshDatabase;

    private FakeTelegramClient $client;

    private function action(): StartTelegramPostAction
    {
        $this->client = new FakeTelegramClient;

        return new StartTelegramPostAction(new CaptureTelegramMessageAction(
            new CaptureTextNoteAction,
            new CaptureScratchpadLinkAction,
            new CaptureScratchpadPhotoAction,
            new CaptureScratchpadVoiceAction,
            $this->client,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function update(string $text, int $userId = 42, int $chatId = 555): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $userId],
                'text' => $text,
            ],
        ];
    }

    /**
     * @return array{config: TelegramBotConfig, link: TelegramBotLink}
     */
    private function linkedBot(): array
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        $link = TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'telegram_user_id' => 42,
        ]);

        return ['config' => $config, 'link' => $link];
    }

    public function test_a_bare_post_command_creates_an_awaiting_request_without_capturing_the_command(): void
    {
        ['config' => $config, 'link' => $link] = $this->linkedBot();

        $request = $this->action()->handle(
            $config,
            $link,
            555,
            42,
            $this->update('/post'),
        );

        $this->assertSame(TelegramPostRequest::AWAITING_INPUT, $request->state);
        $this->assertNull($request->source_scratchpad_entry_id);
        $this->assertSame(0, ScratchpadEntry::count());
    }

    public function test_a_redelivered_post_command_does_not_create_a_second_request(): void
    {
        Queue::fake();
        ['config' => $config, 'link' => $link] = $this->linkedBot();
        $update = $this->update('/post write this once');
        $action = $this->action();

        $action->handle($config, $link, 555, 42, $update);
        $action->handle($config, $link, 555, 42, $update);

        $this->assertSame(1, TelegramPostRequest::count());
        $this->assertSame(1, ScratchpadEntry::count());
    }

    public function test_a_redelivered_follow_up_source_reuses_the_pending_request(): void
    {
        Queue::fake();
        ['config' => $config, 'link' => $link] = $this->linkedBot();
        $action = $this->action();

        $waiting = $action->handle($config, $link, 555, 42, $this->update('/post'));
        $source = $this->update('turn this into a post once');
        $source['update_id'] = 2;

        $first = $action->handle($config, $link, 555, 42, $source, null, $waiting);
        $replayed = $action->handle($config, $link, 555, 42, $source);

        $this->assertTrue($replayed->is($first));
        $this->assertSame(1, TelegramPostRequest::count());
        $this->assertSame(1, ScratchpadEntry::count());
    }

    public function test_the_pending_request_is_reused_for_a_text_source(): void
    {
        Queue::fake();
        ['config' => $config, 'link' => $link] = $this->linkedBot();
        $waiting = TelegramPostRequest::factory()->create([
            'workspace_id' => $config->workspace_id,
            'telegram_bot_config_id' => $config->id,
            'telegram_user_id' => 42,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::AWAITING_INPUT,
        ]);

        $request = $this->action()->handle(
            $config,
            $link,
            555,
            42,
            $this->update('turn this into a post'),
            null,
            $waiting,
        );

        $this->assertTrue($request->is($waiting));
        $this->assertSame(TelegramPostRequest::GENERATING, $request->state);
        $this->assertSame('turn this into a post', $request->sourceEntry->body);
        $this->assertSame(1, TelegramPostRequest::count());
        Queue::assertPushed(GenerateTelegramPostJob::class, fn (GenerateTelegramPostJob $job): bool => $job->telegramPostRequestId === $request->id);
    }

    public function test_a_photo_command_preserves_the_caption_as_an_instruction(): void
    {
        Queue::fake();
        Storage::fake('scratchpad');
        $fakeImage = UploadedFile::fake()->image('source.jpg', 20, 10);
        $imageBytes = file_get_contents($fakeImage->getRealPath());
        ['config' => $config, 'link' => $link] = $this->linkedBot();
        $this->client = new FakeTelegramClient;
        $this->client->willDownloadFile(TelegramFileDownloadResult::success($imageBytes));

        $update = [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 555, 'type' => 'private'],
                'from' => ['id' => 42],
                'caption' => '/post write a concise post about this image',
                'photo' => [['file_id' => 'photo-id', 'width' => 20, 'height' => 10]],
            ],
        ];

        $request = (new StartTelegramPostAction(new CaptureTelegramMessageAction(
            new CaptureTextNoteAction,
            new CaptureScratchpadLinkAction,
            new CaptureScratchpadPhotoAction,
            new CaptureScratchpadVoiceAction,
            $this->client,
        )))->handle($config, $link, 555, 42, $update, 'write a concise post about this image');

        $this->assertSame(TelegramPostRequest::GENERATING, $request->state);
        $this->assertSame('write a concise post about this image', $request->sourceEntry->body);
        $this->assertSame('photo', $request->sourceEntry->kind);
        Queue::assertPushed(GenerateTelegramPostJob::class);
    }
}
