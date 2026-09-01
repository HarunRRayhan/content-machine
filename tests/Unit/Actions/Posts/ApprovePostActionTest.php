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
}
