<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\CancelTelegramPostRequestAction;
use App\Models\Post;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramPostRequest;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelTelegramPostRequestActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_cancels_an_active_request_without_deleting_the_record(): void
    {
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->for($workspace)->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'telegram_user_id' => 42,
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'telegram_user_id' => 42,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::AWAITING_INPUT,
        ]);

        (new CancelTelegramPostRequestAction)->handle($request, $config, 42, 555);

        $this->assertSame(TelegramPostRequest::CANCELLED, $request->refresh()->state);
        $this->assertNotNull($request->cancelled_at);
        $this->assertDatabaseHas('telegram_post_requests', ['id' => $request->id]);
    }

    public function test_it_does_not_cancel_a_publish_already_claimed_by_the_worker(): void
    {
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->for($workspace)->create();
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'telegram_user_id' => 42,
        ]);
        $post = Post::factory()->for($workspace)->create(['publish_state' => 'running']);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'telegram_user_id' => 42,
            'telegram_chat_id' => 555,
            'post_id' => $post->id,
            'state' => TelegramPostRequest::APPROVED,
        ]);

        (new CancelTelegramPostRequestAction)->handle($request, $config, 42, 555);

        $this->assertSame(TelegramPostRequest::APPROVED, $request->refresh()->state);
    }
}
