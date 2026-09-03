<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RequeueLegacyMediaJobsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_moves_unreserved_legacy_media_jobs_to_volume_backed_queues(): void
    {
        $timestamp = now()->timestamp;
        $insert = fn (string $class, string $queue, ?int $reservedAt = null) => DB::table('jobs')->insertGetId([
            'queue' => $queue,
            'payload' => json_encode(['displayName' => $class], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => $reservedAt,
            'available_at' => $timestamp,
            'created_at' => $timestamp,
        ]);

        $scratchpadJob = $insert('App\\Jobs\\TranscribeVoiceNoteJob', 'default');
        $postsyncerJob = $insert('App\\Jobs\\PublishPostJob', 'default');
        $reservedJob = $insert('App\\Jobs\\GenerateTelegramPostJob', 'default', $timestamp);
        $unrelatedJob = $insert('App\\Jobs\\SendTelegramOutboundMessageJob', 'default');

        $this->artisan('cm:requeue-legacy-media-jobs')
            ->assertSuccessful()
            ->expectsOutput('Requeued 2 legacy media job(s).');

        $this->assertDatabaseHas('jobs', ['id' => $scratchpadJob, 'queue' => 'scratchpad']);
        $this->assertDatabaseHas('jobs', ['id' => $postsyncerJob, 'queue' => 'postsyncer']);
        $this->assertDatabaseHas('jobs', ['id' => $reservedJob, 'queue' => 'default']);
        $this->assertDatabaseHas('jobs', ['id' => $unrelatedJob, 'queue' => 'default']);
    }
}
