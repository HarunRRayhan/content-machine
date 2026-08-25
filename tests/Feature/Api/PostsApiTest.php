<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostsApiTest extends TestCase
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

    public function test_store_imports_a_post_with_explicit_human_id(): void
    {
        $this->acting()->postJson('/api/v1/posts', [
            'human_id' => 'BP-12',
            'number' => 12,
            'title' => 'Open weights meme',
            'language' => 'bn',
            'body' => 'caption body',
            'platforms' => ['facebook', 'instagram'],
            'captions' => ['facebook' => 'fb text'],
            'status' => 'posted',
        ])
            ->assertCreated()
            ->assertJsonPath('data.human_id', 'BP-12')
            ->assertJsonPath('data.platforms.0', 'facebook');

        $this->acting()->patchJson('/api/v1/posts/BP-12', [
            'status' => 'archived',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
    }

    public function test_index_lists_posts(): void
    {
        Post::factory()->for($this->workspace)->create(['human_id' => 'P-1', 'number' => 1]);
        Post::factory()->for($this->workspace)->create(['human_id' => 'P-2', 'number' => 2]);

        $this->acting()->getJson('/api/v1/posts')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
