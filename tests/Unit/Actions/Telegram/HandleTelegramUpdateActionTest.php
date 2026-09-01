<?php

namespace Tests\Unit\Actions\Telegram;

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
use App\Jobs\GenerateTelegramPostJob;
use App\Jobs\PublishPostJob;
use App\Jobs\ResolveScratchpadLinkJob;
use App\Models\AiProviderCredential;
use App\Models\Post;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramLinkCode;
use App\Models\TelegramPostRequest;
use App\Models\User;
use App\Models\Video;
use App\Support\AiProviders\AiCompletionClientContract;
use App\Support\AiProviders\AiCompletionResult;
use App\Support\AiProviders\AiProviderCredentialResolver;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class HandleTelegramUpdateActionTest extends TestCase
{
    use RefreshDatabase;

    private FakeTelegramClient $client;

    private function action(?AiCompletionClientContract $completionClient = null): HandleTelegramUpdateAction
    {
        $this->client = new FakeTelegramClient;

        $completionClient ??= new class implements AiCompletionClientContract
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
                $this->client,
            ),
            new CaptureTextNoteAction,
            new LinkTelegramAccountAction,
            new GenerateTelegramChatReplyAction($completionClient, new AiProviderCredentialResolver),
            new ResolveTelegramIntentAction($completionClient, new AiProviderCredentialResolver),
            new DeleteRecentScratchpadEntriesAction(new DeleteScratchpadEntryAction),
            $this->client,
        );
    }

    private function update(int $fromId, string $text, int $chatId = 555): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'message_id' => 42,
                'chat' => ['id' => $chatId],
                'from' => ['id' => $fromId, 'username' => 'sender'],
                'text' => $text,
            ],
        ];
    }

    private function lastReply(): string
    {
        return $this->client->sentMessages[count($this->client->sentMessages) - 1]['text'];
    }

    public function test_an_unlinked_sender_sending_plain_text_is_asked_to_link_and_nothing_is_captured()
    {
        $config = TelegramBotConfig::factory()->connected()->create();

        $this->action()->handle($config, $this->update(1, 'a stray thought'));

        $this->assertSame(0, ScratchpadEntry::count());
        $this->assertStringContainsString('/link', $this->lastReply());
    }

    public function test_an_unlinked_sender_can_still_use_start_and_help()
    {
        $config = TelegramBotConfig::factory()->connected()->create();

        $this->action()->handle($config, $this->update(1, '/start'));
        $this->assertStringContainsString('/link', $this->lastReply());

        $this->action()->handle($config, $this->update(1, '/help'));
        $this->assertStringContainsString('/videos', $this->lastReply());
    }

    public function test_link_with_no_code_prompts_for_one()
    {
        $config = TelegramBotConfig::factory()->connected()->create();

        $this->action()->handle($config, $this->update(1, '/link'));

        $this->assertStringContainsString('/link followed by the code', $this->lastReply());
        $this->assertSame(0, TelegramBotLink::count());
    }

    public function test_link_with_an_unrecognized_code_fails_honestly()
    {
        $config = TelegramBotConfig::factory()->connected()->create();

        $this->action()->handle($config, $this->update(1, '/link NOPE1234'));

        $this->assertStringContainsString("don't recognize", $this->lastReply());
        $this->assertSame(0, TelegramBotLink::count());
    }

    public function test_link_with_a_valid_code_links_the_account()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create(['name' => 'Ada Lovelace']);
        $linkCode = TelegramLinkCode::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'code' => 'ABC12345',
        ]);

        $this->action()->handle($config, $this->update(1, '/link '.$linkCode->code));

        $link = TelegramBotLink::sole();
        $this->assertSame($user->id, $link->user_id);
        $this->assertSame(1, $link->telegram_user_id);
        $this->assertSame('sender', $link->telegram_username);
        $this->assertStringContainsString('Ada Lovelace', $this->lastReply());
    }

    public function test_a_linked_sender_can_ask_who_they_are()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);

        $this->action()->handle($config, $this->update(1, '/me'));

        $this->assertStringContainsString('Grace Hopper', $this->lastReply());
        $this->assertStringContainsString('grace@example.com', $this->lastReply());
    }

    public function test_videos_command_lists_recent_videos_for_the_workspace()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);
        Video::factory()->create(['workspace_id' => $config->workspace_id, 'human_id' => 'V-9', 'title' => 'A great video']);

        $this->action()->handle($config, $this->update(1, '/videos'));

        $this->assertStringContainsString('V-9', $this->lastReply());
        $this->assertStringContainsString('A great video', $this->lastReply());
    }

    public function test_videos_command_with_none_yet_says_so()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);

        $this->action()->handle($config, $this->update(1, '/videos'));

        $this->assertSame('No videos yet.', $this->lastReply());
    }

    public function test_posts_command_lists_recent_posts_for_the_workspace()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);
        Post::factory()->create(['workspace_id' => $config->workspace_id, 'human_id' => 'P-4', 'title' => 'A great post']);

        $this->action()->handle($config, $this->update(1, '/posts'));

        $this->assertStringContainsString('P-4', $this->lastReply());
        $this->assertStringContainsString('A great post', $this->lastReply());
    }

    public function test_notes_command_lists_recent_scratchpad_captures_for_the_workspace()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);
        ScratchpadEntry::factory()->create(['workspace_id' => $config->workspace_id, 'kind' => 'text', 'body' => 'remember to renew the domain', 'status' => 'new']);

        $this->action()->handle($config, $this->update(1, '/notes'));

        $this->assertStringContainsString('text', $this->lastReply());
        $this->assertStringContainsString('remember to renew the domain', $this->lastReply());
        $this->assertStringContainsString('new', $this->lastReply());
    }

    public function test_notes_command_with_none_yet_says_so()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);

        $this->action()->handle($config, $this->update(1, '/notes'));

        $this->assertSame('No Scratch Pad captures yet.', $this->lastReply());
    }

    public function test_clearnotes_command_deletes_recent_untriaged_notes()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);
        ScratchpadEntry::factory()->for($config->workspace)->count(2)->create();
        ScratchpadEntry::factory()->for($config->workspace)->triaged()->create();

        $this->action()->handle($config, $this->update(1, '/clearnotes'));

        $this->assertSame('Deleted 2 notes.', $this->lastReply());
        $this->assertSame(1, ScratchpadEntry::count());
    }

    public function test_clearnotes_command_with_nothing_to_delete_says_so()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);

        $this->action()->handle($config, $this->update(1, '/clearnotes'));

        $this->assertSame('No notes to delete.', $this->lastReply());
    }

    public function test_plain_text_asking_to_delete_notes_runs_clearnotes_via_intent_resolution()
    {
        $config = TelegramBotConfig::factory()->connected()->aiChatEnabled()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $config->workspace_id]);
        ScratchpadEntry::factory()->for($config->workspace)->create();

        $completionClient = new class implements AiCompletionClientContract
        {
            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                if (str_contains($systemPrompt, 'Classify the user')) {
                    return AiCompletionResult::success('clear_notes');
                }

                throw new RuntimeException('a recognized intent should never fall through to a normal chat reply');
            }
        };

        $this->action($completionClient)->handle($config, $this->update(1, 'delete all of these'));

        $this->assertSame('Deleted 1 note.', $this->lastReply());
        $this->assertSame(0, ScratchpadEntry::count());
    }

    public function test_note_command_with_no_text_prompts_for_it()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);

        $this->action()->handle($config, $this->update(1, '/note'));

        $this->assertSame(0, ScratchpadEntry::count());
        $this->assertStringContainsString('/note followed by', $this->lastReply());
    }

    public function test_note_command_captures_a_text_note()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);

        $this->action()->handle($config, $this->update(1, '/note remember to renew the domain'));

        $entry = ScratchpadEntry::sole();
        $this->assertSame('remember to renew the domain', $entry->body);
        $this->assertSame('Captured.', $this->lastReply());
    }

    public function test_an_unknown_command_from_a_linked_sender_gets_a_clear_reply()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);

        $this->action()->handle($config, $this->update(1, '/frobnicate'));

        $this->assertStringContainsString('Unknown command', $this->lastReply());
    }

    public function test_a_linked_sender_sending_plain_text_is_captured_as_normal()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);

        $this->action()->handle($config, $this->update(1, 'a captured thought'));

        $entry = ScratchpadEntry::sole();
        $this->assertSame('a captured thought', $entry->body);
        $this->assertSame('Captured.', $this->lastReply());
    }

    public function test_plain_text_with_ai_chat_enabled_gets_a_chat_reply_instead_of_being_captured()
    {
        $config = TelegramBotConfig::factory()->connected()->aiChatEnabled()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $config->workspace_id]);

        $completionClient = new class implements AiCompletionClientContract
        {
            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::success('Sure, brainstorm away.');
            }
        };

        $this->action($completionClient)->handle($config, $this->update(1, 'got any ideas?'));

        $this->assertSame(0, ScratchpadEntry::count());
        $this->assertSame('Sure, brainstorm away.', $this->lastReply());
    }

    public function test_ai_chat_enabled_but_no_provider_falls_back_to_capture()
    {
        $config = TelegramBotConfig::factory()->connected()->aiChatEnabled()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);

        $completionClient = new class implements AiCompletionClientContract
        {
            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                throw new RuntimeException('should never be called with no provider configured');
            }
        };

        $this->action($completionClient)->handle($config, $this->update(1, 'got any ideas?'));

        $entry = ScratchpadEntry::sole();
        $this->assertSame('got any ideas?', $entry->body);
        $this->assertSame('Captured.', $this->lastReply());
    }

    public function test_a_url_with_ai_chat_enabled_still_captures_as_a_link_without_calling_ai()
    {
        $config = TelegramBotConfig::factory()->connected()->aiChatEnabled()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $config->workspace_id]);

        $completionClient = new class implements AiCompletionClientContract
        {
            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                throw new RuntimeException('a URL should never be routed to AI chat');
            }
        };

        Queue::fake();
        $this->action($completionClient)->handle($config, $this->update(1, 'https://example.com/article'));

        $entry = ScratchpadEntry::sole();
        $this->assertSame('link', $entry->kind);
    }

    public function test_plain_text_asking_for_notes_runs_the_notes_command_via_intent_resolution()
    {
        $config = TelegramBotConfig::factory()->connected()->aiChatEnabled()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $config->workspace_id]);
        ScratchpadEntry::factory()->create(['workspace_id' => $config->workspace_id, 'kind' => 'text', 'body' => 'remember to renew the domain', 'status' => 'new']);

        $completionClient = new class implements AiCompletionClientContract
        {
            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                if (str_contains($systemPrompt, 'Classify the user')) {
                    return AiCompletionResult::success('notes');
                }

                throw new RuntimeException('a recognized intent should never fall through to a normal chat reply');
            }
        };

        $this->action($completionClient)->handle($config, $this->update(1, 'show me my scratchpad'));

        $this->assertSame(0, ScratchpadEntry::query()->where('body', 'show me my scratchpad')->count());
        $this->assertStringContainsString('remember to renew the domain', $this->lastReply());
    }

    public function test_when_every_provider_fails_the_reply_says_so_before_capturing_anyway()
    {
        $config = TelegramBotConfig::factory()->connected()->aiChatEnabled()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $config->workspace_id]);

        $completionClient = new class implements AiCompletionClientContract
        {
            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::failure('provider is down');
            }
        };

        $this->action($completionClient)->handle($config, $this->update(1, 'got any ideas?'));

        $messages = array_column($this->client->sentMessages, 'text');
        $this->assertContains("Couldn't generate a chat reply right now, so I saved this as a note instead.", $messages);
        $this->assertSame('Captured.', end($messages));

        $entry = ScratchpadEntry::sole();
        $this->assertSame('got any ideas?', $entry->body);
    }

    public function test_note_command_still_captures_even_with_ai_chat_enabled()
    {
        $config = TelegramBotConfig::factory()->connected()->aiChatEnabled()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $config->workspace_id]);

        $completionClient = new class implements AiCompletionClientContract
        {
            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                throw new RuntimeException('/note should never be routed to AI chat');
            }
        };

        $this->action($completionClient)->handle($config, $this->update(1, '/note remember to renew the domain'));

        $entry = ScratchpadEntry::sole();
        $this->assertSame('remember to renew the domain', $entry->body);
    }

    public function test_every_message_gets_an_instant_reaction_and_typing_indicator()
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);

        $this->action()->handle($config, $this->update(1, 'a captured thought'));

        $this->assertCount(1, $this->client->reactionsSet);
        $this->assertSame(42, $this->client->reactionsSet[0]['messageId']);
        $this->assertSame(555, $this->client->reactionsSet[0]['chatId']);
        $this->assertSame('❤', $this->client->reactionsSet[0]['emoji']);

        $this->assertNotEmpty($this->client->chatActionsSent);
        $this->assertSame('typing', $this->client->chatActionsSent[0]['action']);
    }

    public function test_a_message_with_no_message_id_skips_the_reaction_but_still_types()
    {
        $config = TelegramBotConfig::factory()->connected()->create();

        $update = [
            'update_id' => 1,
            'message' => [
                'chat' => ['id' => 555],
                'from' => ['id' => 1, 'username' => 'sender'],
                'text' => 'hey',
            ],
        ];

        $this->action()->handle($config, $update);

        $this->assertSame([], $this->client->reactionsSet);
        $this->assertNotEmpty($this->client->chatActionsSent);
    }

    public function test_the_typing_indicator_is_resent_before_each_ai_call()
    {
        $config = TelegramBotConfig::factory()->connected()->aiChatEnabled()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $config->workspace_id]);

        $completionClient = new class implements AiCompletionClientContract
        {
            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::success('none');
            }
        };

        $this->action($completionClient)->handle($config, $this->update(1, 'got any ideas?'));

        // Once on arrival, once before intent resolution, once before the
        // chat completion call itself: three separate blocking steps in
        // this flow, each needs its own resend.
        $this->assertCount(3, $this->client->chatActionsSent);
    }

    public function test_help_text_mentions_chat_when_ai_chat_is_enabled()
    {
        $config = TelegramBotConfig::factory()->connected()->aiChatEnabled()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 1]);

        $this->action()->handle($config, $this->update(1, '/help'));

        $this->assertStringContainsString("I'll chat back", $this->lastReply());
    }

    public function test_post_command_captures_source_and_queues_generation_without_publishing(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'telegram_user_id' => 1,
        ]);

        $this->action()->handle($config, $this->update(1, '/post turn this into a post'));

        $request = TelegramPostRequest::sole();
        $this->assertSame(TelegramPostRequest::GENERATING, $request->state);
        $this->assertSame('turn this into a post', $request->sourceEntry->body);
        $this->assertSame(0, Post::count());
        Queue::assertPushed(GenerateTelegramPostJob::class, fn (GenerateTelegramPostJob $job): bool => $job->telegramPostRequestId === $request->id);
        $this->assertStringContainsString('creating a draft', $this->lastReply());
    }

    public function test_bare_post_command_waits_for_the_next_message(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'telegram_user_id' => 1,
        ]);

        $this->action()->handle($config, $this->update(1, '/post'));
        $waiting = TelegramPostRequest::sole();
        $this->assertSame(TelegramPostRequest::AWAITING_INPUT, $waiting->state);

        $this->action()->handle($config, $this->update(1, 'the next source'));

        $request = $waiting->refresh();
        $this->assertSame(TelegramPostRequest::GENERATING, $request->state);
        $this->assertSame('the next source', $request->sourceEntry->body);
        $this->assertSame(1, TelegramPostRequest::count());
        $this->assertStringContainsString('creating a draft', $this->lastReply());
    }

    public function test_approve_command_approves_the_latest_generated_draft(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'telegram_user_id' => 1,
        ]);
        $post = Post::factory()->create([
            'workspace_id' => $config->workspace_id,
            'approval_state' => 'pending',
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $config->workspace_id,
            'telegram_bot_config_id' => $config->id,
            'post_id' => $post->id,
            'telegram_user_id' => 1,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::AWAITING_APPROVAL,
        ]);

        $this->action()->handle($config, $this->update(1, '/approve'));

        $this->assertSame('approved', $post->refresh()->approval_state);
        $this->assertSame(TelegramPostRequest::APPROVED, $request->refresh()->state);
        $this->assertStringContainsString('approved', $this->lastReply());
    }

    public function test_post_now_requires_approval_and_queues_only_after_it(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'telegram_user_id' => 1,
        ]);
        $post = Post::factory()->create([
            'workspace_id' => $config->workspace_id,
            'approval_state' => 'approved',
            'captions' => [[
                'part' => null,
                'lang' => 'bn',
                'platforms' => [[
                    'name' => 'facebook',
                    'title' => 'Title',
                    'caption' => 'Caption',
                    'first_comment' => '',
                    'images' => [],
                    'thread' => [],
                ]],
            ]],
            'platforms' => ['facebook'],
        ]);
        TelegramPostRequest::factory()->create([
            'workspace_id' => $config->workspace_id,
            'telegram_bot_config_id' => $config->id,
            'post_id' => $post->id,
            'telegram_user_id' => 1,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::APPROVED,
        ]);
        PostsyncerConfig::write($config->workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-key',
            'languages' => [
                'english' => ['workspace_id' => '123', 'platforms' => []],
                'bangla' => ['workspace_id' => '123', 'platforms' => []],
            ],
            'post_types' => ['platforms' => ['facebook' => ['text' => 'on']]],
        ]);

        $this->action()->handle($config, $this->update(1, '/post-now'));

        $this->assertSame('queued', $post->refresh()->publish_state, $this->lastReply());
        $this->assertStringContainsString('queued', $this->lastReply());
    }

    public function test_post_link_waits_for_link_resolution_before_generation(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'telegram_user_id' => 1,
        ]);

        $this->action()->handle($config, $this->update(1, '/post https://example.com/article'));

        $request = TelegramPostRequest::sole();
        $this->assertSame(TelegramPostRequest::GENERATING, $request->state);
        Queue::assertPushed(ResolveScratchpadLinkJob::class, fn (ResolveScratchpadLinkJob $job): bool => $job->entry->is($request->sourceEntry));
        Queue::assertNotPushed(GenerateTelegramPostJob::class);
    }

    public function test_schedule_queues_an_approved_draft_for_the_requested_time(): void
    {
        Queue::fake();
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'telegram_user_id' => 1,
        ]);
        $post = Post::factory()->create([
            'workspace_id' => $config->workspace_id,
            'approval_state' => 'approved',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Scheduled caption'],
        ]);
        TelegramPostRequest::factory()->create([
            'workspace_id' => $config->workspace_id,
            'telegram_bot_config_id' => $config->id,
            'post_id' => $post->id,
            'telegram_user_id' => 1,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::APPROVED,
        ]);
        PostsyncerConfig::write($config->workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-key',
            'languages' => [
                'english' => ['workspace_id' => '123', 'platforms' => []],
                'bangla' => ['workspace_id' => '123', 'platforms' => []],
            ],
            'post_types' => ['platforms' => ['facebook' => ['text' => 'on']]],
        ]);

        $this->action()->handle($config, $this->update(1, '/schedule P-'.$post->number.' 2099-09-03 09:00'));

        $this->assertSame('queued', $post->refresh()->publish_state);
        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job): bool => $job->post->is($post)
            && $job->options['when'] === '2099-09-03T09:00:00+06:00');
        $this->assertStringContainsString('queued', $this->lastReply());
    }

    public function test_cancel_marks_the_active_request_cancelled_without_deleting_the_source(): void
    {
        $config = TelegramBotConfig::factory()->connected()->create();
        $user = User::factory()->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'telegram_user_id' => 1,
        ]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $config->workspace_id,
            'body' => 'Keep this source.',
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $config->workspace_id,
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $entry->id,
            'telegram_user_id' => 1,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::GENERATING,
        ]);

        $this->action()->handle($config, $this->update(1, '/cancel'));

        $this->assertSame(TelegramPostRequest::CANCELLED, $request->refresh()->state);
        $this->assertNotNull($entry->fresh());
        $this->assertStringContainsString('Cancelled', $this->lastReply());
    }
}
