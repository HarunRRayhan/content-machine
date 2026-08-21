<?php

namespace Tests\Feature\Database\Migrations;

use App\Models\TelegramBotConfig;
use App\Models\Workspace;
use App\Support\Telegram\TelegramBotCommands;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Telegram\FakeTelegramClient;
use Tests\TestCase;

/**
 * Exercises the migration's own up() logic directly (rather than only via
 * `php artisan migrate`, which by the time this test suite runs has an
 * empty telegram_bot_configs table and so never actually runs the
 * migration's loop body). This is the one migration in this app that
 * makes a live external call, so it gets its own coverage where the
 * others (pure schema/data changes) don't.
 */
class SyncCommandsForAlreadyConnectedTelegramBotsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_21_120726_sync_commands_for_already_connected_telegram_bots.php');
    }

    public function test_it_registers_commands_for_every_connected_bot()
    {
        $client = new FakeTelegramClient;
        $this->app->instance(TelegramClientContract::class, $client);

        $connected = TelegramBotConfig::factory()->connected()->create(['bot_token' => '123:abc']);
        TelegramBotConfig::factory()->create();

        $this->migration()->up();

        $this->assertSame(1, count($client->setMyCommandsCalledWith));
        $this->assertSame('123:abc', $client->setMyCommandsCalledWith[0]['botToken']);
        $this->assertSame(TelegramBotCommands::LIST, $client->setMyCommandsCalledWith[0]['commands']);
    }

    public function test_an_unreadable_token_does_not_stop_the_rest_of_the_sync()
    {
        $client = new FakeTelegramClient;
        $this->app->instance(TelegramClientContract::class, $client);

        DB::table('telegram_bot_configs')->insert([
            'workspace_id' => Workspace::factory()->create()->id,
            'bot_token' => 'not-actually-encrypted',
            'ai_chat_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        TelegramBotConfig::factory()->connected()->create(['bot_token' => '456:def']);

        $this->migration()->up();

        $this->assertSame(['456:def'], array_column($client->setMyCommandsCalledWith, 'botToken'));
    }
}
