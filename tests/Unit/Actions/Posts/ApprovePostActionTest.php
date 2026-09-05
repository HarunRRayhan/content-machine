<?php

namespace Tests\Unit\Actions\Posts;

use App\Actions\Posts\ApprovePostAction;
use App\Models\Post;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramPostRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApprovePostActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_approves_the_post_and_its_waiting_telegram_request(): void
    {
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->create(['workspace_id' => $workspace->id]);
        $user = User::factory()->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $user->id,
            'telegram_user_id' => 42,
        ]);
        $post = Post::factory()->create([
            'workspace_id' => $workspace->id,
            'approval_state' => 'pending',
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'post_id' => $post->id,
            'telegram_user_id' => 42,
            'state' => TelegramPostRequest::AWAITING_APPROVAL,
        ]);

        (new ApprovePostAction)->handle($post, $user);

        $this->assertSame('approved', $post->refresh()->approval_state);
        $this->assertSame($user->id, $post->approved_by_user_id);
        $this->assertSame(TelegramPostRequest::APPROVED, $request->refresh()->state);
        $this->assertNotNull($request->confirmed_at);
    }

    public function test_it_syncs_an_awaiting_request_when_the_post_is_already_approved(): void
    {
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->create(['workspace_id' => $workspace->id]);
        $post = Post::factory()->create([
            'workspace_id' => $workspace->id,
            'approval_state' => 'approved',
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'post_id' => $post->id,
            'state' => TelegramPostRequest::AWAITING_APPROVAL,
        ]);
        $actor = User::factory()->create();

        (new ApprovePostAction)->handle($post, $actor);

        $this->assertSame(TelegramPostRequest::APPROVED, $request->refresh()->state);
        $this->assertNotNull($request->confirmed_at);
    }

    public function test_a_telegram_approval_only_advances_the_request_that_was_confirmed(): void
    {
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->for($workspace)->create();
        $post = Post::factory()->for($workspace)->create(['approval_state' => 'pending']);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'post_id' => $post->id,
            'state' => TelegramPostRequest::AWAITING_APPROVAL,
        ]);
        $otherRequest = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'post_id' => $post->id,
            'state' => TelegramPostRequest::AWAITING_APPROVAL,
        ]);
        $actor = User::factory()->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $actor->id,
            'telegram_user_id' => $request->telegram_user_id,
        ]);

        (new ApprovePostAction)->handle($post, $actor, $request, $config, $request->telegram_user_id, $request->telegram_chat_id);

        $this->assertSame('approved', $post->refresh()->approval_state);
        $this->assertSame(TelegramPostRequest::APPROVED, $request->refresh()->state);
        $this->assertSame(TelegramPostRequest::AWAITING_APPROVAL, $otherRequest->refresh()->state);
    }

    public function test_it_reapproves_a_scheduled_post_after_a_publish(): void
    {
        $workspace = Workspace::factory()->create();
        $actor = User::factory()->create();
        $post = Post::factory()->for($workspace)->create([
            'approval_state' => 'pending',
            'status' => 'scheduled',
        ]);

        (new ApprovePostAction)->handle($post, $actor);

        $this->assertSame('approved', $post->refresh()->approval_state);
        $this->assertSame($actor->id, $post->approved_by_user_id);
    }

    public function test_it_rejects_approval_for_an_archived_post_with_validation(): void
    {
        $workspace = Workspace::factory()->create();
        $actor = User::factory()->create();
        $post = Post::factory()->for($workspace)->create([
            'approval_state' => 'pending',
            'status' => 'archived',
        ]);

        $this->expectException(ValidationException::class);

        (new ApprovePostAction)->handle($post, $actor);
    }
}
