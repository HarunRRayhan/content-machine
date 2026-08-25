<?php

namespace Tests\Feature\Console;

use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedPostsyncerSettingsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/postsyncer-seed-'.uniqid('', true);
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir.'/*') ?: []);
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_it_imports_workspaces_post_types_and_api_key(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);

        $workspacesPath = $this->tempDir.'/workspaces.json';
        $postTypesPath = $this->tempDir.'/post_types.json';

        file_put_contents($workspacesPath, json_encode([
            'bangla' => [
                'language' => 'Bangla',
                'workspace_id' => 15211,
                'platforms' => [
                    'facebook' => ['handle' => 'HarunRRayhan', 'account_id' => 7017],
                ],
            ],
            'english' => [
                'language' => 'English',
                'workspace_id' => 853,
                'platforms' => [
                    'twitter' => ['handle' => 'harundotdev', 'account_id' => 1205],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($postTypesPath, json_encode([
            '_comment' => 'ignored',
            'types' => [['key' => 'text', 'label' => 'Text']],
            'platforms' => [
                'facebook' => ['text' => 'on', 'photo' => 'on'],
                'twitter' => ['text' => 'on', 'photo' => 'off'],
            ],
            'overrides' => [
                'bangla' => [
                    'twitter' => ['text' => 'off', 'photo' => 'off'],
                    'linkedin' => ['text' => null, 'photo' => null],
                ],
                'english' => [
                    'twitter' => ['photo' => 'off'],
                    'threads' => ['photo' => 'ask'],
                ],
            ],
            'notes' => ['twitter' => 'ignored note'],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('postsyncer:seed', [
            'workspace_id' => $workspace->id,
            '--workspaces' => $workspacesPath,
            '--post-types' => $postTypesPath,
            '--api-key' => 'imported-api-key',
        ])
            ->expectsOutputToContain("PostSyncer settings imported for workspace {$workspace->id}.")
            ->assertExitCode(0);

        $config = PostsyncerConfig::fromWorkspace($workspace->fresh());

        $this->assertSame('imported-api-key', $config->apiKey());
        $this->assertTrue($config->publishEnabled());

        $bangla = $config->language('bangla');
        $this->assertSame('15211', $bangla['workspace_id']);
        $this->assertSame(7017, $bangla['platforms']['facebook']['account_id']);
        $this->assertSame('HarunRRayhan', $bangla['platforms']['facebook']['handle']);

        $english = $config->language('english');
        $this->assertSame('853', $english['workspace_id']);
        $this->assertSame(1205, $english['platforms']['twitter']['account_id']);

        $postTypes = $config->postTypes();
        $this->assertSame('on', $postTypes['platforms']['facebook']['text']);
        $this->assertSame('off', $postTypes['overrides']['bangla']['twitter']['text']);
        $this->assertNull($postTypes['overrides']['bangla']['linkedin']['text']);
        $this->assertSame('ask', $postTypes['overrides']['english']['threads']['photo']);
        $this->assertArrayNotHasKey('_comment', $postTypes);
        $this->assertArrayNotHasKey('types', $postTypes);
        $this->assertArrayNotHasKey('notes', $postTypes);
    }

    public function test_it_fails_when_workspace_is_missing(): void
    {
        $workspacesPath = $this->tempDir.'/workspaces.json';
        $postTypesPath = $this->tempDir.'/post_types.json';
        file_put_contents($workspacesPath, '{}');
        file_put_contents($postTypesPath, '{"platforms": {}, "overrides": {}}');

        $this->artisan('postsyncer:seed', [
            'workspace_id' => 99999,
            '--workspaces' => $workspacesPath,
            '--post-types' => $postTypesPath,
            '--api-key' => 'key',
        ])
            ->expectsOutputToContain('Workspace 99999 not found.')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_json_file_is_missing(): void
    {
        $workspace = Workspace::factory()->create();

        $this->artisan('postsyncer:seed', [
            'workspace_id' => $workspace->id,
            '--workspaces' => $this->tempDir.'/missing-workspaces.json',
            '--post-types' => $this->tempDir.'/missing-post-types.json',
            '--api-key' => 'key',
        ])
            ->expectsOutputToContain('File not found:')
            ->assertExitCode(1);
    }
}
