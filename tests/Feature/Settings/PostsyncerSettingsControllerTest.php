<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_edit_omits_workspaces_until_api_key_is_configured(): void
    {
        $this->actingAsWorkspaceOwner();

        $this->get(route('dashboard.postsyncer.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/postsyncer')
                ->where('apiKeyConfigured', false)
                ->where('availableWorkspaces', [])
                ->where('workspacesLoadError', null));
    }

    public function test_edit_auto_loads_workspaces_when_api_key_is_configured(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'api_base' => 'https://postsyncer.com/api/v1',
        ]);

        Http::fake([
            'postsyncer.com/api/v1/accounts' => Http::response([
                'data' => [
                    [
                        'id' => 1,
                        'workspace_id' => 15211,
                        'workspace_name' => 'Bangla',
                        'platform' => 'facebook',
                        'username' => 'harun',
                    ],
                    [
                        'id' => 2,
                        'workspace_id' => 853,
                        'workspace_name' => 'English',
                        'platform' => 'twitter',
                        'username' => 'harun',
                    ],
                ],
            ], 200),
        ]);

        $this->get(route('dashboard.postsyncer.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/postsyncer')
                ->where('apiKeyConfigured', true)
                ->where('availableWorkspaces', [
                    ['id' => '15211', 'label' => 'Bangla'],
                    ['id' => '853', 'label' => 'English'],
                ])
                ->where('workspacesLoadError', null));
    }
}
