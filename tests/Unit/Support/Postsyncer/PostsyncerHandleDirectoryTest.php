<?php

namespace Tests\Unit\Support\Postsyncer;

use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerHandleDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostsyncerHandleDirectoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Workspace, 1: PostsyncerHandleDirectory}
     */
    private function directory(): array
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'api_base' => 'https://postsyncer.com/api/v1',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['handle' => 'HarunRRayhan', 'account_id' => 7017],
                    ],
                ],
                'english' => [
                    'workspace_id' => '853',
                    'platforms' => [
                        'twitter' => ['handle' => 'old-english-twitter', 'account_id' => 1205],
                    ],
                ],
            ],
        ]);
        $workspace->refresh();
        $config = PostsyncerConfig::fromWorkspace($workspace);

        return [
            $workspace,
            new PostsyncerHandleDirectory(
                new PostsyncerClient($config),
                $config,
                (string) $workspace->id,
            ),
        ];
    }

    public function test_it_maps_handles_from_both_workspaces_and_ignores_others(): void
    {
        Cache::flush();
        Http::fake([
            'postsyncer.com/api/v1/accounts' => Http::response([
                [
                    'id' => 7017,
                    'workspace_id' => 15211,
                    'platform' => 'facebook',
                    'username' => null,
                    'name' => 'Harun R.',
                ],
                [
                    'id' => 7368,
                    'workspace_id' => 15211,
                    'platform' => 'twitter',
                    'username' => 'HarunRRayhan',
                    'name' => 'Harun R. Rayhan',
                ],
                [
                    'id' => 1205,
                    'workspace_id' => 853,
                    'platform' => 'twitter',
                    'username' => 'harundotdev',
                    'name' => 'Harun R.',
                ],
                [
                    'id' => 4936,
                    'workspace_id' => 42761,
                    'platform' => 'instagram',
                    'username' => 'armansedits',
                    'name' => 'Arman',
                ],
            ], 200),
        ]);

        [, $directory] = $this->directory();
        $handles = $directory->forPreview();

        $this->assertSame('HarunRRayhan', $handles['bn']['facebook']['handle']);
        $this->assertSame('Harun R. Rayhan', $handles['bn']['facebook']['name']);
        $this->assertSame('HarunRRayhan', $handles['bn']['twitter']['handle']);
        $this->assertSame('harundotdev', $handles['en']['twitter']['handle']);
        $this->assertSame('Harun R. Rayhan', $handles['en']['twitter']['name']);
        $this->assertSame('harundotdev', $handles['en']['instagram']['handle']);
        Http::assertSentCount(1);
    }

    public function test_it_falls_back_to_stored_handles_when_the_api_fails(): void
    {
        Cache::flush();
        Http::fake([
            'postsyncer.com/api/v1/accounts' => Http::response(['message' => 'nope'], 500),
        ]);

        [, $directory] = $this->directory();
        $handles = $directory->forPreview();

        $this->assertSame('HarunRRayhan', $handles['bn']['facebook']['handle']);
        $this->assertSame('old-english-twitter', $handles['en']['twitter']['handle']);
    }

    public function test_it_fills_missing_settings_from_the_studio_workspace_map(): void
    {
        Cache::flush();
        Http::fake([
            'postsyncer.com/api/v1/accounts' => Http::response([], 200),
        ]);

        $workspace = Workspace::factory()->create(['settings' => []]);
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'api_base' => 'https://postsyncer.com/api/v1',
            'languages' => [
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
        ]);
        $workspace->refresh();
        $config = PostsyncerConfig::fromWorkspace($workspace);
        $directory = new PostsyncerHandleDirectory(
            new PostsyncerClient($config),
            $config,
            (string) $workspace->id,
        );

        $handles = $directory->forPreview();

        $this->assertSame('HarunRRayhan', $handles['bn']['facebook']['handle']);
        $this->assertSame('skillupwithharun', $handles['bn']['youtube']['handle']);
        $this->assertSame('harundotdev', $handles['en']['twitter']['handle']);
        $this->assertSame('harun.dev', $handles['en']['bluesky']['handle']);
    }
}
