<?php

namespace Tests\Feature\Settings;

use App\Models\Post;
use App\Models\User;
use App\Models\Video;
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

    public function test_owner_can_disable_publishing_while_a_publish_is_queued(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'publish_enabled' => true,
            'languages' => [
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
        ]);
        Post::factory()->for($workspace)->create([
            'publish_state' => 'queued',
        ]);

        $this->put(route('settings.postsyncer.update'), [
            'page' => 'api',
            'publish_enabled' => false,
            'api_base' => 'https://postsyncer.com/api/v1',
            'upload_base' => 'https://upload.postsyncer.com/api/v1',
        ])
            ->assertRedirect(route('settings.postsyncer.edit'));

        $this->assertFalse(PostsyncerConfig::fromWorkspace($workspace->fresh())->publishEnabled());
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
            'postsyncer.com/api/v1/workspaces' => Http::response([
                'data' => [
                    [
                        'id' => 15211,
                        'name' => 'Bangla',
                        'slug' => 'bangla',
                        'accounts' => [
                            [
                                'id' => 7017,
                                'platform' => 'facebook',
                                'username' => 'HarunRRayhan',
                            ],
                        ],
                    ],
                    [
                        'id' => 853,
                        'name' => 'English',
                        'slug' => 'english',
                        'accounts' => [
                            [
                                'id' => 1205,
                                'platform' => 'twitter',
                                'username' => 'harundotdev',
                            ],
                        ],
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
                    [
                        'id' => '15211',
                        'name' => 'Bangla',
                        'accounts' => [
                            ['id' => '7017', 'platform' => 'facebook', 'handle' => '@HarunRRayhan'],
                        ],
                    ],
                    [
                        'id' => '853',
                        'name' => 'English',
                        'accounts' => [
                            ['id' => '1205', 'platform' => 'twitter', 'handle' => '@harundotdev'],
                        ],
                    ],
                ])
                ->where('workspacesLoadError', null)
                ->where('postsyncerConnected', true)
                ->where('postTypes.platforms.facebook.text', 'on')
                ->where('postTypes.overrides.english.twitter.photo', 'off')
                ->where('postTypes.overrides.bangla.twitter.text', 'off'));
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

    public function test_workspaces_save_persists_enabled_platforms_and_script_studio_types(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
        ]);

        $this->put(route('settings.postsyncer.update'), [
            'page' => 'workspaces',
            'default_language' => 'english',
            'enabled_languages' => ['english', 'bangla'],
            'languages' => [
                'english' => [
                    'workspace_id' => '853',
                    'platforms' => [
                        'facebook' => ['account_id' => '1205', 'handle' => '@harundotdev', 'enabled' => '1'],
                        'twitter' => ['account_id' => '99', 'handle' => '@harundotdev', 'enabled' => '0'],
                    ],
                ],
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['account_id' => '7017', 'handle' => '@HarunRRayhan', 'enabled' => '1'],
                    ],
                ],
            ],
            'post_types' => [
                'platforms' => [
                    'facebook' => ['text' => 'on', 'photo' => 'on'],
                    'twitter' => ['text' => 'on', 'photo' => 'off'],
                ],
                'overrides' => [
                    'english' => [
                        'twitter' => ['photo' => 'off'],
                    ],
                    'bangla' => [
                        'twitter' => ['text' => 'off'],
                    ],
                ],
            ],
        ])->assertRedirect(route('settings.postsyncer.workspaces'));

        $config = PostsyncerConfig::fromWorkspace($workspace->fresh());

        $this->assertSame('853', $config->language('english')['workspace_id']);
        $this->assertTrue($config->isPlatformEnabled('english', 'facebook'));
        $this->assertFalse($config->isPlatformEnabled('english', 'twitter'));
        $this->assertTrue($config->isPlatformEnabled('bangla', 'facebook'));
        $this->assertSame('on', $config->postTypes()['platforms']['facebook']['text']);
        $this->assertSame('off', $config->postTypes()['overrides']['english']['twitter']['photo']);
    }

    public function test_settings_update_repairs_legacy_account_mapping_checkpoints(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['account_id' => null],
                    ],
                ],
            ],
        ]);

        $legacyProgress = [
            'version' => 1,
            'operation_id' => 'operation-1',
            'run_token' => 'run-1',
            'options' => ['when' => null, 'confirm_ask' => false],
            'plan_hash' => 'legacy-plan',
            'planned_groups' => [['index' => 0, 'group_key' => 'legacy-group']],
            'completed_groups' => [],
            'current' => [
                'index' => 0,
                'group_key' => 'legacy-group',
                'phase' => 'creating',
                'idempotency_key' => 'legacy-request',
                'media_ids' => [915],
                'media_urls' => ['https://example.com/media'],
            ],
            'state' => 'uncertain',
        ];
        $error = 'PostSyncer create outcome is uncertain. Reconcile PostSyncer before retrying. No account id mapped for platform facebook.';

        $post = Post::factory()->for($workspace)->create([
            'publish_state' => 'failed',
            'publish_error' => $error,
            'publish_progress' => $legacyProgress,
        ]);
        $video = Video::factory()->for($workspace)->create([
            'publish_state' => 'failed',
            'publish_error' => $error,
            'publish_progress' => $legacyProgress,
        ]);

        $this->put(route('settings.postsyncer.update'), [
            'page' => 'workspaces',
            'languages' => [
                'bangla' => [
                    'platforms' => [
                        'facebook' => ['account_id' => '100'],
                    ],
                ],
            ],
        ])->assertRedirect(route('settings.postsyncer.workspaces'));

        $this->assertSame(
            '100',
            PostsyncerConfig::fromWorkspace($workspace->fresh())->language('bangla')['platforms']['facebook']['account_id'],
        );

        foreach ([$post, $video] as $record) {
            $record->refresh();
            $this->assertSame('failed', $record->publish_state);
            $this->assertSame('failed', $record->publish_progress['state'], (string) $record->publish_error);
            $this->assertSame('retryable', $record->publish_progress['current']['phase']);
            $this->assertSame('missing_account', $record->publish_progress['legacy_repair']);
            $this->assertTrue($record->canRetryPublish());
        }
    }

    public function test_settings_update_rejects_legacy_checkpoints_with_completed_groups(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['account_id' => null],
                    ],
                ],
            ],
        ]);

        $post = Post::factory()->for($workspace)->create([
            'publish_state' => 'failed',
            'publish_error' => 'No account id mapped for platform facebook.',
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'operation-1',
                'run_token' => 'run-1',
                'options' => ['when' => null, 'confirm_ask' => false],
                'plan_hash' => 'legacy-plan',
                'planned_groups' => [['index' => 0, 'group_key' => 'legacy-group']],
                'completed_groups' => [[
                    'index' => 0,
                    'group_key' => 'legacy-group',
                    'post_id' => '10',
                ]],
                'current' => [
                    'index' => 1,
                    'group_key' => 'legacy-current-group',
                    'phase' => 'creating',
                    'idempotency_key' => 'legacy-request',
                    'media_ids' => [915],
                    'media_urls' => ['https://example.com/media'],
                ],
                'state' => 'uncertain',
            ],
        ]);

        $this->put(route('settings.postsyncer.update'), [
            'page' => 'workspaces',
            'languages' => [
                'bangla' => [
                    'platforms' => [
                        'facebook' => ['account_id' => '100'],
                    ],
                ],
            ],
        ])
            ->assertSessionHasErrors('postsyncer')
            ->assertRedirect();

        $this->assertNull(
            PostsyncerConfig::fromWorkspace($workspace->fresh())
                ->language('bangla')['platforms']['facebook']['account_id'],
        );
        $this->assertSame('failed', $post->fresh()->publish_state);
    }

    public function test_refresh_accounts_uses_the_selected_workspace_id(): void
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
                        'id' => 1205,
                        'workspace_id' => 853,
                        'platform' => 'twitter',
                        'username' => 'harundotdev',
                    ],
                    [
                        'id' => 7017,
                        'workspace_id' => 15211,
                        'platform' => 'facebook',
                        'username' => 'HarunRRayhan',
                    ],
                ],
            ], 200),
        ]);

        $this->post(route('settings.postsyncer.refresh-accounts'), [
            'language' => 'english',
            'workspace_id' => '853',
        ])
            ->assertOk()
            ->assertJsonPath('language', 'english')
            ->assertJsonPath('suggested.twitter.account_id', 1205)
            ->assertJsonPath('suggested.twitter.handle', '@harundotdev')
            ->assertJsonPath('suggested.twitter.enabled', true)
            ->assertJsonPath('suggested.facebook.enabled', false);
    }

    public function test_refresh_accounts_requires_a_workspace_id(): void
    {
        [, $workspace] = $this->actingAsWorkspaceOwner();

        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
        ]);

        $this->post(route('settings.postsyncer.refresh-accounts'), [
            'language' => 'english',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pick a PostSyncer workspace first.');
    }
}
