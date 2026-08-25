<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostsyncerSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function actingAsWorkspaceOwner(): array
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        return [$user, $workspace];
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard.postsyncer.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_owner_can_update_publish_enabled(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        $this->put(route('dashboard.postsyncer.update'), [
            'publish_enabled' => true,
            'api_base' => 'https://postsyncer.com/api/v1',
            'upload_base' => 'https://upload.postsyncer.com/api/v1',
            'languages' => [
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
            'post_types' => ['platforms' => [], 'overrides' => []],
        ])
            ->assertRedirect(route('dashboard.postsyncer.edit'));

        $this->assertTrue(PostsyncerConfig::fromWorkspace($workspace->fresh())->publishEnabled());
    }
}
