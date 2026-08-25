<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\Idea;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdeasApiTest extends TestCase
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

    public function test_index_lists_ideas_with_kind_and_status_filters(): void
    {
        Idea::factory()->for($this->workspace)->create(['kind' => 'post', 'status' => 'open']);
        Idea::factory()->for($this->workspace)->create(['kind' => 'video', 'status' => 'open']);
        Idea::factory()->for($this->workspace)->create(['kind' => 'post', 'status' => 'dropped']);

        $this->acting()->getJson('/api/v1/ideas')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->acting()->getJson('/api/v1/ideas?kind=post')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->acting()->getJson('/api/v1/ideas?kind=post&status=open')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_show_addresses_an_idea_by_human_id(): void
    {
        Idea::factory()->for($this->workspace)->create([
            'kind' => 'post',
            'human_id' => 'PI-7',
            'title' => 'Sync job cost spike',
        ]);

        $this->acting()->getJson('/api/v1/ideas/PI-7')
            ->assertOk()
            ->assertJsonPath('data.human_id', 'PI-7')
            ->assertJsonPath('data.title', 'Sync job cost spike');
    }

    public function test_show_of_another_workspaces_human_id_is_not_found(): void
    {
        Idea::factory()->for(Workspace::factory())->create([
            'kind' => 'post',
            'human_id' => 'PI-1',
        ]);

        $this->acting()->getJson('/api/v1/ideas/PI-1')->assertNotFound();
    }

    public function test_update_edits_through_the_same_action_as_the_dashboard(): void
    {
        Idea::factory()->for($this->workspace)->create([
            'kind' => 'post',
            'human_id' => 'PI-2',
            'score' => 600,
        ]);

        $this->acting()->patchJson('/api/v1/ideas/PI-2', [
            'title' => 'Retitled',
            'score' => 880,
            'trend' => 'seasonal',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Retitled')
            ->assertJsonPath('data.score', 880)
            ->assertJsonPath('data.trend', 'seasonal');

        $idea = Idea::query()->where('human_id', 'PI-2')->sole();
        $this->assertSame(880, $idea->score);
    }

    public function test_store_creates_an_idea_and_reserves_the_next_id(): void
    {
        $this->acting()->postJson('/api/v1/ideas', [
            'kind' => 'post',
            'title' => 'Fresh post idea',
            'score' => 720,
            'trend' => 'evergreen',
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'post')
            ->assertJsonPath('data.title', 'Fresh post idea')
            ->assertJsonPath('data.human_id', 'PI-1');
    }

    public function test_store_imports_idempotently_by_human_id(): void
    {
        $payload = [
            'kind' => 'video',
            'human_id' => 'VI-27',
            'title' => 'Imported video idea',
            'score' => 795,
            'status' => 'open',
            'body' => 'scratch notes',
        ];

        $this->acting()->postJson('/api/v1/ideas', $payload)
            ->assertCreated()
            ->assertJsonPath('data.human_id', 'VI-27');

        $this->acting()->postJson('/api/v1/ideas', $payload)
            ->assertOk()
            ->assertJsonPath('data.human_id', 'VI-27');

        $this->assertSame(1, Idea::query()->where('human_id', 'VI-27')->count());
    }
}
