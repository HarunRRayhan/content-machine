<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Scratchpad\CaptureScratchpadLinkAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Actions\Telegram\CaptureTelegramMessageAction;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Support\Telegram\TelegramApiResult;
use App\Support\Telegram\TelegramClientContract;
use App\Support\Telegram\TelegramGetMeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CaptureTelegramMessageActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Public, not private: the fake TelegramClientContract built in
     * action() is an anonymous class, a genuinely different class from
     * this test even though it's defined inside one of its methods, so it
     * can only reach this property if visibility allows it.
     *
     * @var list<array{0: string, 1: int, 2: string}>
     */
    public array $sentMessages = [];

    private function action(): CaptureTelegramMessageAction
    {
        $this->sentMessages = [];

        $client = new class($this) implements TelegramClientContract
        {
            public function __construct(private readonly CaptureTelegramMessageActionTest $test) {}

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
                $this->test->sentMessages[] = [$botToken, $chatId, $text];

                return TelegramApiResult::success();
            }
        };

        return new CaptureTelegramMessageAction(
            new CaptureTextNoteAction,
            new CaptureScratchpadLinkAction,
            $client,
        );
    }

    private function textUpdate(int $fromId, string $text, int $chatId = 555): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => $chatId],
                'from' => ['id' => $fromId],
                'text' => $text,
            ],
        ];
    }

    public function test_a_first_message_locks_in_the_sender_and_captures_a_text_note()
    {
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);

        $this->action()->handle($config, $this->textUpdate(42, 'A thought worth keeping.'));

        $entry = ScratchpadEntry::sole();
        $this->assertSame('text', $entry->kind);
        $this->assertSame('telegram', $entry->source);
        $this->assertSame('A thought worth keeping.', $entry->body);
        $this->assertSame(42, $config->fresh()->linked_telegram_user_id);
        $this->assertSame([['123:tok', 555, 'Captured.']], $this->sentMessages);
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
        $this->assertSame([['123:tok', 555, '🔗 Link captured.']], $this->sentMessages);
    }

    public function test_a_message_from_a_different_sender_than_the_locked_one_is_rejected()
    {
        $config = TelegramBotConfig::factory()->connected()->create([
            'bot_token' => '123:tok',
            'linked_telegram_user_id' => 42,
        ]);

        $this->action()->handle($config, $this->textUpdate(99, 'I am not Harun.'));

        $this->assertSame(0, ScratchpadEntry::count());
        $this->assertSame(42, $config->fresh()->linked_telegram_user_id);
        $this->assertSame([['123:tok', 555, 'This bot is private.']], $this->sentMessages);
    }

    public function test_a_message_with_no_text_gets_an_honest_not_yet_reply()
    {
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);

        $update = [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 555],
                'from' => ['id' => 42],
                'photo' => [['file_id' => 'abc']],
            ],
        ];

        $this->action()->handle($config, $update);

        $this->assertSame(0, ScratchpadEntry::count());
        $this->assertCount(1, $this->sentMessages);
        $this->assertStringContainsString('coming soon', $this->sentMessages[0][2]);
    }

    public function test_an_update_with_no_message_key_is_ignored()
    {
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);

        $this->action()->handle($config, ['update_id' => 1, 'edited_message' => ['text' => 'edited']]);

        $this->assertSame(0, ScratchpadEntry::count());
        $this->assertSame([], $this->sentMessages);
    }

    public function test_the_same_sender_can_capture_more_than_once()
    {
        $config = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:tok']);

        $action = $this->action();
        $action->handle($config, $this->textUpdate(42, 'First note.'));
        $action->handle($config->fresh(), $this->textUpdate(42, 'Second note.'));

        $this->assertSame(2, ScratchpadEntry::count());
    }
}
