<?php

namespace Tests\Unit\Jobs;

use App\Actions\Telegram\GenerateTelegramPostAction;
use App\Jobs\GenerateTelegramPostJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Models\TelegramPostRequest;
use App\Models\Workspace;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

class GenerateTelegramPostJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_delegates_to_the_action(): void
    {
        $action = Mockery::mock(GenerateTelegramPostAction::class);
        $action->shouldReceive('handle')->once()->with(123);

        (new GenerateTelegramPostJob(123))->handle($action);
    }

    public function test_failed_marks_a_generating_request_as_failed(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $config = TelegramBotConfig::factory()->connected()->create([
            'workspace_id' => $workspace->id,
        ]);
        $request = TelegramPostRequest::factory()->create([
            'workspace_id' => $workspace->id,
            'telegram_bot_config_id' => $config->id,
            'telegram_user_id' => 42,
            'telegram_chat_id' => 555,
            'state' => TelegramPostRequest::GENERATING,
        ]);
        $client = new FakeTelegramClient;
        $this->app->instance(TelegramClientContract::class, $client);

        (new GenerateTelegramPostJob($request->id))->failed(new RuntimeException('provider crashed'));

        $this->assertSame(TelegramPostRequest::FAILED, $request->refresh()->state);
        $message = TelegramOutboundMessage::query()->sole();
        $this->assertStringContainsString('unexpected error', $message->chunks[0]);
    }
}
