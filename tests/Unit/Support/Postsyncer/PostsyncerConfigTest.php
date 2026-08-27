<?php

namespace Tests\Unit\Support\Postsyncer;

use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostsyncerConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_encrypts_api_key_and_read_decrypts(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        PostsyncerConfig::write($workspace, [
            'api_key' => 'secret-key',
            'api_base' => 'https://postsyncer.com/api/v1',
            'publish_enabled' => true,
            'languages' => [
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
            'post_types' => [],
        ]);
        $workspace->refresh();
        $raw = $workspace->settings['postsyncer']['api_key'];
        $this->assertNotSame('secret-key', $raw);
        $this->assertSame('secret-key', PostsyncerConfig::fromWorkspace($workspace)->apiKey());
    }

    public function test_blank_api_key_on_write_keeps_existing(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        PostsyncerConfig::write($workspace, ['api_key' => 'secret-key']);
        $workspace->refresh();

        PostsyncerConfig::write($workspace, [
            'api_key' => '',
            'publish_enabled' => true,
        ]);
        $workspace->refresh();

        $this->assertSame('secret-key', PostsyncerConfig::fromWorkspace($workspace)->apiKey());
        $this->assertTrue(PostsyncerConfig::fromWorkspace($workspace)->publishEnabled());
    }

    public function test_write_merges_one_language_without_wiping_the_other(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
        ]);
        $workspace->refresh();

        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => ['workspace_id' => '999', 'platforms' => []],
            ],
        ]);
        $workspace->refresh();

        $config = PostsyncerConfig::fromWorkspace($workspace);

        $this->assertSame('999', $config->language('bangla')['workspace_id']);
        $this->assertSame('853', $config->language('english')['workspace_id']);
    }

    public function test_defaults_and_accessors(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $config = PostsyncerConfig::fromWorkspace($workspace);

        $this->assertSame('https://postsyncer.com/api/v1', $config->apiBase());
        $this->assertSame('https://upload.postsyncer.com/api/v1', $config->uploadBase());
        $this->assertNull($config->apiKey());
        $this->assertFalse($config->publishEnabled());
        $this->assertFalse($config->isConfigured());
        $this->assertSame(['workspace_id' => null, 'platforms' => []], $config->language('bangla'));
        $this->assertSame([], $config->postTypes());
    }
}
