<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideosApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->token = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('test client'),
        )['plaintext'];
    }

    private function acting(): self
    {
        return $this->withToken($this->token);
    }

    public function test_index_lists_videos_filtered_by_status(): void
    {
        Video::factory()->for($this->workspace)->create(['status' => 'draft', 'number' => 1, 'human_id' => 'V-1']);
        Video::factory()->for($this->workspace)->create(['status' => 'posted', 'number' => 2, 'human_id' => 'V-2']);

        $this->acting()->getJson('/api/v1/videos')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->acting()->getJson('/api/v1/videos?status=posted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.human_id', 'V-2');
    }

    public function test_store_imports_a_video_with_explicit_human_id(): void
    {
        $this->acting()->postJson('/api/v1/videos', [
            'human_id' => 'BV-53',
            'number' => 53,
            'title' => 'Load balancer vs reverse proxy',
            'language' => 'bn',
            'slug' => 'load-balancer-vs-reverse-proxy',
            'script_markdown' => '# script',
            'captions' => ['facebook' => 'hello'],
            'status' => 'posted',
        ])
            ->assertCreated()
            ->assertJsonPath('data.human_id', 'BV-53')
            ->assertJsonPath('data.script_markdown', '# script')
            ->assertJsonPath('data.captions.facebook', 'hello');

        // Idempotent re-import
        $this->acting()->postJson('/api/v1/videos', [
            'human_id' => 'BV-53',
            'number' => 53,
            'title' => 'Load balancer vs reverse proxy',
        ])
            ->assertOk()
            ->assertJsonPath('data.human_id', 'BV-53');

        $this->assertDatabaseCount('videos', 1);
    }

    public function test_show_and_patch_address_by_human_id(): void
    {
        Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-10',
            'number' => 10,
            'title' => 'Old title',
            'status' => 'draft',
        ]);

        $this->acting()->getJson('/api/v1/videos/BV-10')
            ->assertOk()
            ->assertJsonPath('data.title', 'Old title');

        $this->acting()->patchJson('/api/v1/videos/BV-10', [
            'title' => 'New title',
            'status' => 'ready',
            'script_markdown' => 'spoken lines',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'New title')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.script_markdown', 'spoken lines');
    }

    public function test_show_of_another_workspaces_video_is_not_found(): void
    {
        Video::factory()->for(Workspace::factory())->create([
            'human_id' => 'BV-1',
            'number' => 1,
        ]);

        $this->acting()->getJson('/api/v1/videos/BV-1')->assertNotFound();
    }
}
