<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Posts\AttachExistingPostMediaAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Telegram\GenerateTelegramPostAction;
use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialModel;
use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramPostRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AiProviders\AiCompletionClientContract;
use App\Support\AiProviders\AiCompletionResult;
use App\Support\AiProviders\AiProviderCredentialResolver;
use App\Support\AiProviders\AiVisionCompletionClientContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class GenerateTelegramPostActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_pending_post_and_sends_a_preview(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->connected()->create(['workspace_id' => $workspace->id]);
        $user = User::factory()->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'telegram_user_id' => 42,
        ]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'text',
            'source' => 'telegram',
            'body' => 'A useful idea from Telegram.',
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $entry->id,
            'telegram_user_id' => 42,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::GENERATING,
        ]);

        $client = new FakeTelegramClient;
        $completion = new FakePostCompletionClient(json_encode([
            'title' => 'Useful idea',
            'body' => 'A useful idea from Telegram.',
            'language' => 'bn',
            'captions' => [
                'facebook' => ['caption' => 'Facebook caption', 'first_comment' => 'Read more.'],
                'instagram' => ['caption' => 'Instagram caption', 'first_comment' => ''],
            ],
        ], JSON_THROW_ON_ERROR));

        $post = (new GenerateTelegramPostAction(
            app(CreatePostAction::class),
            new AttachExistingPostMediaAction,
            $completion,
            $completion,
            new AiProviderCredentialResolver,
            $client,
        ))->handle($request->id);

        $this->assertInstanceOf(Post::class, $post);
        $this->assertSame('pending', $post->approval_state);
        $this->assertSame('Useful idea', $post->title);
        $this->assertSame(TelegramPostRequest::AWAITING_APPROVAL, $request->refresh()->state);
        $this->assertSame($post->id, $request->post_id);
        $this->assertCount(1, $client->sentMessages);
        $this->assertStringContainsString($post->human_id, $client->sentMessages[0]['text']);
        $this->assertStringContainsString('/approve', $client->sentMessages[0]['text']);
    }

    public function test_a_photo_uses_the_vision_chain_and_attaches_the_source_image(): void
    {
        Queue::fake();
        Storage::fake('scratchpad');
        Storage::disk('scratchpad')->put('images/source.jpg', 'image-bytes');

        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->connected()->create(['workspace_id' => $workspace->id]);
        AiProviderCredential::factory()->withModel('gpt-4o', 'vision')->create(['workspace_id' => $workspace->id, 'provider' => 'openai']);

        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'photo',
            'source' => 'telegram',
            'body' => null,
        ]);
        $media = MediaAsset::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'image',
            'disk' => 'scratchpad',
            'path' => 'images/source.jpg',
            'mime' => 'image/jpeg',
            'original_filename' => 'source.jpg',
        ]);
        Attachment::factory()->create([
            'attachable_type' => $entry->getMorphClass(),
            'attachable_id' => $entry->id,
            'media_asset_id' => $media->id,
            'role' => 'image',
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $entry->id,
            'telegram_user_id' => 42,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::GENERATING,
        ]);

        $completion = new FakePostCompletionClient(json_encode([
            'title' => 'Photo idea',
            'body' => 'A post about this photo.',
            'language' => 'bn',
            'captions' => [
                'facebook' => ['caption' => 'Photo caption', 'first_comment' => ''],
                'instagram' => ['caption' => 'Photo caption for Instagram', 'first_comment' => ''],
            ],
        ], JSON_THROW_ON_ERROR));

        $post = (new GenerateTelegramPostAction(
            app(CreatePostAction::class),
            new AttachExistingPostMediaAction,
            $completion,
            $completion,
            new AiProviderCredentialResolver,
            new FakeTelegramClient,
        ))->handle($request->id);

        $this->assertInstanceOf(Post::class, $post);
        $this->assertSame(1, $post->attachments()->count());
        $this->assertSame('source.jpg', $post->attachments()->first()->mediaAsset->original_filename);
        $this->assertTrue($completion->visionCalled);
    }

    public function test_cancellation_during_completion_does_not_create_a_post(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->connected()->create(['workspace_id' => $workspace->id]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'text',
            'body' => 'A useful idea from Telegram.',
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $entry->id,
            'state' => TelegramPostRequest::GENERATING,
        ]);
        $completion = new class($request) implements AiCompletionClientContract
        {
            public function __construct(private readonly TelegramPostRequest $request) {}

            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                $this->request->update(['state' => TelegramPostRequest::CANCELLED]);

                return AiCompletionResult::success(json_encode([
                    'title' => 'Should not be saved',
                    'body' => 'Should not be saved',
                    'language' => 'bn',
                    'captions' => [
                        'facebook' => ['caption' => 'Should not be saved', 'first_comment' => ''],
                        'instagram' => ['caption' => 'Should not be saved', 'first_comment' => ''],
                    ],
                ], JSON_THROW_ON_ERROR));
            }
        };

        $post = (new GenerateTelegramPostAction(
            app(CreatePostAction::class),
            new AttachExistingPostMediaAction,
            $completion,
            new FakePostCompletionClient('{}'),
            new AiProviderCredentialResolver,
            new FakeTelegramClient,
        ))->handle($request->id);

        $this->assertNull($post);
        $this->assertSame(0, Post::count());
        $this->assertSame(TelegramPostRequest::CANCELLED, $request->refresh()->state);
    }

    public function test_malformed_ai_json_fails_without_creating_a_post(): void
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->connected()->create(['workspace_id' => $workspace->id]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'text',
            'source' => 'telegram',
            'body' => 'An untrusted source.',
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => $entry->id,
            'state' => TelegramPostRequest::GENERATING,
        ]);
        $completion = new FakePostCompletionClient(json_encode([
            'title' => ['not', 'a', 'string'],
            'body' => 'Body',
            'language' => 'bn',
            'captions' => [
                'facebook' => ['caption' => 'Facebook', 'first_comment' => ''],
                'instagram' => ['caption' => 'Instagram', 'first_comment' => ''],
            ],
        ], JSON_THROW_ON_ERROR));

        $post = (new GenerateTelegramPostAction(
            app(CreatePostAction::class),
            new AttachExistingPostMediaAction,
            $completion,
            $completion,
            new AiProviderCredentialResolver,
            new FakeTelegramClient,
        ))->handle($request->id);

        $this->assertNull($post);
        $this->assertSame(0, Post::count());
        $this->assertSame(TelegramPostRequest::FAILED, $request->refresh()->state);
        $this->assertSame('The AI returned an unusable draft. Please try generating it again.', $request->error_message);
    }
}

final class FakePostCompletionClient implements AiCompletionClientContract, AiVisionCompletionClientContract
{
    public bool $visionCalled = false;

    public function __construct(private readonly string $response) {}

    public function complete(AiProviderCredentialModel $entry, string $systemPrompt, string $userContent): AiCompletionResult
    {
        return AiCompletionResult::success($this->response);
    }

    public function completeWithImage(
        AiProviderCredentialModel $entry,
        string $systemPrompt,
        string $userContent,
        string $mimeType,
        string $imageContents,
    ): AiCompletionResult {
        $this->visionCalled = true;

        return AiCompletionResult::success($this->response);
    }
}
