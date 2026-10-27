<?php

namespace Tests\Feature\Console;

use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileVideoMediaAbsentCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_without_the_confirm_absent_flag(): void
    {
        $workspace = Workspace::factory()->create();
        Video::factory()->for($workspace)->create([
            'human_id' => 'BV-MABSENT',
            'publish_state' => 'failed',
            'publish_progress' => null,
        ]);

        $this->artisan('postsyncer:reconcile-video-media-absent', [
            'workspace_id' => $workspace->id,
            'video' => 'BV-MABSENT',
        ])
            ->expectsOutputToContain('Refusing to continue without --confirm-absent')
            ->assertFailed();
    }

    public function test_it_reports_a_video_that_has_no_uncertain_upload(): void
    {
        $workspace = Workspace::factory()->create();
        Video::factory()->for($workspace)->create([
            'human_id' => 'BV-NOUPLOAD',
            'publish_state' => 'failed',
            'publish_progress' => null,
        ]);

        $this->artisan('postsyncer:reconcile-video-media-absent', [
            'workspace_id' => $workspace->id,
            'video' => 'BV-NOUPLOAD',
            '--confirm-absent' => true,
        ])
            ->expectsOutputToContain('no PostSyncer progress to reconcile')
            ->assertFailed();
    }
}
