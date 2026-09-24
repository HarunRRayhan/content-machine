<?php

namespace Tests\Unit\Actions\Notifications;

use App\Actions\Notifications\QueueTelegramNotificationAction;
use App\Data\Notifications\CreateTelegramNotificationData;
use App\Jobs\SendTelegramOutboundMessageJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramOutboundMessage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueTelegramNotificationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_logical_keys_serialize_to_one_outbox_row_and_dispatch(): void
    {
        Queue::fake();
        $workspace = Workspace::factory()->create();
        $owner = User::factory()->create();
        DB::table('team_user')->insert([
            'team_id' => $workspace->team_id,
            'user_id' => $owner->id,
            'role' => 'member',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $config = TelegramBotConfig::factory()->connected()->create(['workspace_id' => $workspace->id]);
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $config->id,
            'user_id' => $owner->id,
            'telegram_user_id' => 111222333,
        ]);
        $action = app(QueueTelegramNotificationAction::class);
        $data = new CreateTelegramNotificationData('stable-key', 'Nudge copy');

        $first = $action->handle($workspace, $owner, $data);
        $second = $action->handle($workspace, $owner, $data);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['message']->id, $second['message']->id);
        $this->assertSame(1, TelegramOutboundMessage::query()->count());
        Queue::assertPushed(SendTelegramOutboundMessageJob::class, 1);
    }
}
