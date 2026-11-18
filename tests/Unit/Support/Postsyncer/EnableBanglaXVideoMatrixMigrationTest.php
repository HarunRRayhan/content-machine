<?php

namespace Tests\Unit\Support\Postsyncer;

use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnableBanglaXVideoMatrixMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        $migration = require database_path('migrations/2026_10_17_000001_enable_bangla_x_video_matrix.php');
        $migration->up();
    }

    public function test_sets_bangla_twitter_override_and_leaves_everything_else(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        PostsyncerConfig::write($workspace, [
            'languages' => ['bangla' => ['workspace_id' => '15211', 'platforms' => ['twitter' => ['account_id' => 102]]]],
            'post_types' => [
                'platforms' => ['facebook' => ['text' => 'on', 'reel' => 'on']],
                'overrides' => [
                    'bangla' => ['twitter' => ['text' => 'off'], 'linkedin' => ['text' => null]],
                    'english' => ['twitter' => ['photo' => 'off']],
                ],
            ],
        ]);

        $this->migrate();
        $this->migrate();

        $workspace->refresh();
        $config = PostsyncerConfig::fromWorkspace($workspace);
        $postTypes = $config->postTypes();

        // jsonb does not keep key order, so compare as maps.
        $this->assertEquals(
            ['text' => 'on', 'photo' => 'on', 'carousel' => 'on', 'thread' => 'off', 'reel' => 'on'],
            $postTypes['overrides']['bangla']['twitter'],
        );
        $this->assertSame(['text' => null], $postTypes['overrides']['bangla']['linkedin']);
        $this->assertSame(['photo' => 'off'], $postTypes['overrides']['english']['twitter']);
        $this->assertEquals(['text' => 'on', 'reel' => 'on'], $postTypes['platforms']['facebook']);
        $this->assertSame('102', (string) $config->language('bangla')['platforms']['twitter']['account_id']);
        $this->assertSame('15211', $config->language('bangla')['workspace_id']);
    }

    public function test_workspaces_without_a_stored_matrix_are_untouched(): void
    {
        $workspace = Workspace::factory()->create(['settings' => ['postsyncer' => ['default_language' => 'bangla']]]);

        $this->migrate();

        $workspace->refresh();
        $this->assertSame(['postsyncer' => ['default_language' => 'bangla']], $workspace->settings);
    }
}
