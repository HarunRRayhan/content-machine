<?php

namespace Tests\Feature\Mcp;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Jobs\PublishPostJob;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\GoogleDrive\GoogleDriveConfig;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class McpServerTest extends TestCase
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
            new CreateWorkspaceApiTokenData('mcp client'),
        )['plaintext'];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function mcp(array $body)
    {
        return $this->withToken($this->token)->postJson('/mcp', $body);
    }

    public function test_unauthenticated_mcp_calls_are_rejected(): void
    {
        $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ])->assertUnauthorized();
    }

    public function test_preflight_does_not_need_a_token(): void
    {
        $this->options('/mcp')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_initialize_returns_server_info_and_cors(): void
    {
        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1'],
            ],
        ])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertJsonPath('result.serverInfo.name', 'content-machine')
            ->assertJsonPath('result.protocolVersion', '2025-03-26');
    }

    public function test_tools_list_includes_scratchpad_ideas_videos_and_posts(): void
    {
        $names = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ])
            ->assertOk()
            ->json('result.tools');

        $this->assertIsArray($names);
        $listed = array_column($names, 'name');
        $this->assertContains('list_scratchpad', $listed);
        $this->assertContains('list_media', $listed);
        $this->assertContains('update_idea', $listed);
        $this->assertContains('list_videos', $listed);
        $this->assertContains('get_video', $listed);
        $this->assertContains('update_video', $listed);
        $this->assertContains('check_drive_url', $listed);
        $this->assertContains('list_drive_files', $listed);
        $this->assertContains('make_drive_file_public', $listed);
        $this->assertContains('publish_video', $listed);
        $this->assertContains('list_posts', $listed);
        $this->assertContains('get_post', $listed);
        $this->assertContains('update_post', $listed);
        $this->assertContains('publish_post', $listed);
    }

    public function test_list_media_returns_a_bearer_authenticated_preview_url(): void
    {
        $asset = MediaAsset::factory()->for($this->workspace)->create();

        $text = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 21,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_media',
                'arguments' => [],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError')->json('result.content.0.text');

        $this->assertIsString($text);
        $this->assertStringContainsString(
            str_replace('/', '\\/', route('api.v1.media.file', ['mediaAsset' => $asset->public_id])),
            $text,
        );
        $this->assertStringNotContainsString(
            str_replace('/', '\\/', route('media.file', $asset)),
            $text,
        );
    }

    public function test_capture_note_and_list_scratchpad_round_trip(): void
    {
        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'capture_note',
                'arguments' => ['body' => 'MCP captured this'],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError');

        $entry = ScratchpadEntry::query()->sole();
        $this->assertSame('MCP captured this', $entry->body);
        $this->assertSame('api', $entry->source);

        $listed = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_scratchpad',
                'arguments' => ['status' => 'new'],
            ],
        ])->assertOk()->json('result.content.0.text');

        $this->assertIsString($listed);
        $this->assertStringContainsString($entry->public_id, $listed);
        $this->assertStringContainsString('MCP captured this', $listed);
    }

    public function test_missing_ability_is_a_tool_error_not_a_hard_401(): void
    {
        $readOnly = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('read only', ['scratchpad:read', 'ideas:read']),
        )['plaintext'];

        $this->withToken($readOnly)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'capture_note',
                'arguments' => ['body' => 'nope'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'Token is missing the [scratchpad:write] ability.');

        $this->assertSame(0, ScratchpadEntry::query()->count());
    }

    public function test_notification_returns_202(): void
    {
        $this->mcp([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ])->assertAccepted();
    }

    public function test_get_and_list_video_round_trip(): void
    {
        Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-50',
            'number' => 50,
            'title' => 'Load balancer',
            'language' => 'bn',
            'status' => 'draft',
        ]);

        $listed = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_videos',
                'arguments' => ['status' => 'draft', 'language' => 'bn'],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError')->json('result.content.0.text');

        $this->assertIsString($listed);
        $this->assertStringContainsString('BV-50', $listed);
        $this->assertStringContainsString('Load balancer', $listed);

        $got = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_video',
                'arguments' => ['human_id' => 'BV-50'],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError')->json('result.content.0.text');

        $this->assertIsString($got);
        $this->assertStringContainsString('BV-50', $got);
        $this->assertStringContainsString('Load balancer', $got);
    }

    public function test_update_video_changes_a_field(): void
    {
        Video::factory()->for($this->workspace)->create([
            'human_id' => 'V-12',
            'number' => 12,
            'title' => 'Old title',
            'status' => 'draft',
        ]);

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/call',
            'params' => [
                'name' => 'update_video',
                'arguments' => ['human_id' => 'V-12', 'title' => 'New title'],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError');

        $this->assertSame('New title', Video::query()->where('human_id', 'V-12')->value('title'));
    }

    public function test_update_video_accepts_a_renderable_deck_manifest(): void
    {
        Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-12',
            'number' => 12,
            'title' => 'Deck video',
        ]);

        $manifest = [
            'engine' => 'stage',
            'deck_key' => 'v-12',
            'js' => "window.PRESENTATIONS['v-12']={steps:[{cue:'First line'}],stage:function(){return '<div>Deck</div>';}};",
        ];

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 80,
            'method' => 'tools/call',
            'params' => [
                'name' => 'update_video',
                'arguments' => ['human_id' => 'BV-12', 'deck_manifest' => $manifest],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError');

        $stored = Video::query()->where('human_id', 'BV-12')->firstOrFail();
        $this->assertSame($manifest['js'], $stored->deck_manifest['js']);
    }

    public function test_update_video_accepts_drive_urls(): void
    {
        $this->fakeAccessibleDriveLinks();

        Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-60',
            'number' => 60,
            'title' => 'Recorded cut',
            'status' => 'recorded',
        ]);

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 81,
            'method' => 'tools/call',
            'params' => [
                'name' => 'update_video',
                'arguments' => [
                    'human_id' => 'BV-60',
                    'video_drive_url' => 'https://drive.google.com/file/d/publicFile/view',
                ],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError');

        $this->assertSame(
            'https://drive.google.com/file/d/publicFile/view',
            Video::query()->where('human_id', 'BV-60')->value('video_drive_url'),
        );
    }

    public function test_check_drive_url_reports_a_private_file(): void
    {
        $this->fakePrivateDriveLinks();

        $text = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 82,
            'method' => 'tools/call',
            'params' => [
                'name' => 'check_drive_url',
                'arguments' => [
                    'url' => 'https://drive.google.com/file/d/privateFile/view',
                ],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError')->json('result.content.0.text');

        $this->assertIsString($text);
        $this->assertStringContainsString('"accessible":false', $text);
    }

    public function test_drive_mcp_tools_list_files_and_make_one_public(): void
    {
        Config::set([
            'services.google_drive.client_id' => 'drive-client-id',
            'services.google_drive.client_secret' => 'drive-client-secret',
        ]);

        GoogleDriveConfig::storeTokens($this->workspace, [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour()->timestamp,
        ]);

        $metadataCalls = 0;
        Http::fake(function (ClientRequest $request) use (&$metadataCalls) {
            if (str_contains($request->url(), '/permissions')) {
                return Http::response(['id' => 'anyoneWithLink']);
            }

            if (str_contains($request->url(), '/files/file-id')) {
                $metadataCalls++;

                return Http::response([
                    'id' => 'file-id',
                    'name' => '062-final.mp4',
                    'mimeType' => 'video/mp4',
                    'permissions' => $metadataCalls === 1
                        ? []
                        : [['type' => 'anyone', 'role' => 'reader']],
                ]);
            }

            return Http::response([
                'files' => [[
                    'id' => 'file-id',
                    'name' => '062-final.mp4',
                    'mimeType' => 'video/mp4',
                    'permissions' => [],
                ]],
            ]);
        });

        $listed = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 83,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_drive_files',
                'arguments' => ['q' => '062'],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError')->json('result.content.0.text');

        $this->assertIsString($listed);
        $this->assertStringContainsString('062-final.mp4', $listed);

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 84,
            'method' => 'tools/call',
            'params' => [
                'name' => 'make_drive_file_public',
                'arguments' => ['file_id' => 'file-id'],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError');

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/permissions')
            && $request['type'] === 'anyone'
            && $request['role'] === 'reader');
    }

    public function test_missing_videos_write_is_a_tool_error_and_does_not_write(): void
    {
        Video::factory()->for($this->workspace)->create([
            'human_id' => 'V-12',
            'number' => 12,
            'title' => 'Keep me',
        ]);

        $readOnly = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('read only', ['videos:read']),
        )['plaintext'];

        $this->withToken($readOnly)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => [
                'name' => 'update_video',
                'arguments' => ['human_id' => 'V-12', 'title' => 'nope'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'Token is missing the [videos:write] ability.');

        $this->assertSame('Keep me', Video::query()->where('human_id', 'V-12')->value('title'));
    }

    public function test_get_and_list_post_round_trip(): void
    {
        Post::factory()->for($this->workspace)->create([
            'human_id' => 'P-50',
            'number' => 50,
            'title' => 'Open weights meme',
            'language' => 'bn',
            'status' => 'draft',
        ]);

        $listed = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_posts',
                'arguments' => ['status' => 'draft', 'language' => 'bn'],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError')->json('result.content.0.text');

        $this->assertIsString($listed);
        $this->assertStringContainsString('P-50', $listed);
        $this->assertStringContainsString('Open weights meme', $listed);

        $got = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_post',
                'arguments' => ['human_id' => 'P-50'],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError')->json('result.content.0.text');

        $this->assertIsString($got);
        $this->assertStringContainsString('P-50', $got);
        $this->assertStringContainsString('Open weights meme', $got);
    }

    public function test_update_post_changes_a_field(): void
    {
        Post::factory()->for($this->workspace)->create([
            'human_id' => 'BP-7',
            'number' => 7,
            'title' => 'Old post',
            'status' => 'draft',
        ]);

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => 'tools/call',
            'params' => [
                'name' => 'update_post',
                'arguments' => ['human_id' => 'BP-7', 'title' => 'New post'],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError');

        $this->assertSame('New post', Post::query()->where('human_id', 'BP-7')->value('title'));
    }

    public function test_publish_post_queues_the_job(): void
    {
        Queue::fake();
        PostsyncerConfig::write($this->workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-api-key',
            'languages' => [
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
        ]);

        $post = Post::factory()->for($this->workspace)->create([
            'human_id' => 'CM-TEST-4',
            'number' => 4,
            'publish_state' => 'idle',
        ]);

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 14,
            'method' => 'tools/call',
            'params' => [
                'name' => 'publish_post',
                'arguments' => [
                    'human_id' => 'CM-TEST-4',
                    'when' => '2026-08-28T22:00:00+06:00',
                    'platforms' => ['facebook'],
                ],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError');

        $this->assertSame('queued', $post->fresh()->publish_state);

        Queue::assertPushed(PublishPostJob::class, function (PublishPostJob $job) use ($post) {
            return $job->post->is($post)
                && $job->options['when'] === '2026-08-28T22:00:00+06:00'
                && $job->options['platforms'] === ['facebook'];
        });
    }

    public function test_missing_posts_write_is_a_tool_error_and_does_not_write(): void
    {
        Post::factory()->for($this->workspace)->create([
            'human_id' => 'BP-7',
            'number' => 7,
            'title' => 'Keep me',
        ]);

        $readOnly = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('read only', ['posts:read']),
        )['plaintext'];

        $this->withToken($readOnly)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 13,
            'method' => 'tools/call',
            'params' => [
                'name' => 'update_post',
                'arguments' => ['human_id' => 'BP-7', 'title' => 'nope'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'Token is missing the [posts:write] ability.');

        $this->assertSame('Keep me', Post::query()->where('human_id', 'BP-7')->value('title'));
    }

    public function test_api_access_page_exposes_the_mcp_url(): void
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->get(route('dashboard.team.api-tokens.index'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('dashboard/api-tokens')
                    ->where('mcp_url', url('/mcp')),
            );
    }
}
