<?php

namespace Tests\Feature\Database\Migrations;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyTelegramReplayMarkerMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_only_legacy_jobs_present_in_the_queue(): void
    {
        $config = TelegramBotConfig::factory()->connected()->create();

        DB::table('telegram_updates')->insert([
            [
                'telegram_bot_config_id' => $config->id,
                'webhook_generation' => $config->webhook_generation,
                'update_id' => 71,
                'payload' => null,
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'telegram_bot_config_id' => $config->id,
                'webhook_generation' => $config->webhook_generation,
                'update_id' => 72,
                'payload' => null,
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // This test exercises the migration body a second time with a queue
        // that contains both the old serialized shape and the current one.
        $migration = require database_path(
            'migrations/2026_09_03_000001_add_legacy_replay_marker_to_telegram_updates.php',
        );
        $migration->down();

        Queue::connection('database')->push(new ProcessTelegramUpdateJob(
            $config->id,
            ['update_id' => 71],
        ));
        Queue::connection('database')->push(new ProcessTelegramUpdateJob(
            $config->id,
            ['update_id' => 72],
            $config->webhook_generation,
        ));

        $migration->up();

        $this->assertTrue((bool) DB::table('telegram_updates')
            ->where('update_id', 71)
            ->value('legacy_replayable'));
        $this->assertFalse((bool) DB::table('telegram_updates')
            ->where('update_id', 72)
            ->value('legacy_replayable'));
        $this->assertTrue(Schema::hasColumn('telegram_updates', 'legacy_replayable'));
    }
}
