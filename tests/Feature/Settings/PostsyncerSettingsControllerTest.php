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
        $this->get(route('settings.postsyncer.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_settings_index_redirects_to_general(): void
    {
        $this->actingAsWorkspaceOwner();

        $this->get(route('settings.index'))
            ->assertRedirect('/settings/general');
    }

    public function test_legacy_dashboard_url_redirects_to_settings(): void
    {
        $this->actingAsWorkspaceOwner();

        $this->get('/dashboard/settings/postsyncer')
            ->assertRedirect('/settings/postsyncer');
    }

    public function test_legacy_step_urls_redirect(): void
    {
        $this->actingAsWorkspaceOwner();

        $this->get('/settings/postsyncer/connecting')
            ->assertRedirect('/settings/postsyncer');
        $this->get('/settings/postsyncer/bangla')
            ->assertRedirect('/settings/postsyncer/workspaces');
    }

    public function test_owner_can_update_publish_enabled(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        $this->put(route('settings.postsyncer.update'), [
            'page' => 'api',
            'publish_enabled' => true,
            'api_base' => 'https://postsyncer.com/api/v1',
            'upload_base' => 'https://upload.postsyncer.com/api/v1',
        ])
            ->assertRedirect(route('settings.postsyncer.edit'));

        $this->assertTrue(PostsyncerConfig::fromWorkspace($workspace->fresh())->publishEnabled());
    }

    public function test_api_page_omits_workspaces_until_api_key_is_configured(): void
    {
        $this->actingAsWorkspaceOwner();

        $this->get(route('settings.postsyncer.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('workspace-settings/postsyncer-api')
                ->where('apiKeyConfigured', false)
                ->where('availableWorkspaces', [])
                ->where('workspacesLoadError', null));
    }

    public function test_workspaces_page_redirects_until_api_key_is_configured(): void
    {
        $this->actingAsWorkspaceOwner();

        $this->get(route('settings.postsyncer.workspaces'))
            ->assertRedirect(route('settings.postsyncer.edit'));
    }

    public function test_workspaces_page_auto_loads_workspaces_when_api_key_is_configured(): void
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

        $this->get(route('settings.postsyncer.workspaces'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('workspace-settings/postsyncer-workspaces')
                ->where('apiKeyConfigured', true)
                ->where('defaultLanguage', 'english')
                ->where('availableWorkspaces', [
                    ['id' => '15211', 'label' => 'Bangla'],
                    ['id' => '853', 'label' => 'English'],
                ])
                ->where('workspacesLoadError', null));
    }

    public function test_api_save_does_not_wipe_language_workspaces(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
        ]);

        $this->put(route('settings.postsyncer.update'), [
            'page' => 'api',
            'publish_enabled' => true,
            'api_base' => 'https://postsyncer.com/api/v1',
            'upload_base' => 'https://upload.postsyncer.com/api/v1',
        ])->assertRedirect(route('settings.postsyncer.edit'));

        $config = PostsyncerConfig::fromWorkspace($workspace->fresh());

        $this->assertTrue($config->publishEnabled());
        $this->assertSame('15211', $config->language('bangla')['workspace_id']);
        $this->assertSame('853', $config->language('english')['workspace_id']);
    }

    public function test_workspaces_save_can_keep_only_the_default_language(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'default_language' => 'english',
            'enabled_languages' => ['english', 'bangla'],
            'languages' => [
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
        ]);

        $this->put(route('settings.postsyncer.update'), [
            'page' => 'workspaces',
            'default_language' => 'english',
            'enabled_languages' => ['english'],
            'languages' => [
                'english' => ['workspace_id' => '853'],
                'bangla' => ['workspace_id' => ''],
            ],
        ])->assertRedirect(route('settings.postsyncer.workspaces'));

        $config = PostsyncerConfig::fromWorkspace($workspace->fresh());

        $this->assertSame('english', $config->defaultLanguage());
        $this->assertSame(['english'], $config->enabledLanguages());
        $this->assertSame('853', $config->language('english')['workspace_id']);
        $this->assertNull($config->language('bangla')['workspace_id']);
    }
}
