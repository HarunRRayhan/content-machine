<?php

namespace Tests\Unit\Support\Postsyncer;

use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PostsyncerClientTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithKey(string $apiKey = 'test-api-key'): PostsyncerClient
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        PostsyncerConfig::write($workspace, [
            'api_key' => $apiKey,
            'api_base' => 'https://postsyncer.com/api/v1',
        ]);
        $workspace->refresh();

        return new PostsyncerClient(PostsyncerConfig::fromWorkspace($workspace));
    }

    public function test_upload_from_urls_returns_media_ids(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
                'count_stored' => 1,
            ], 200),
        ]);

        $client = $this->clientWithKey();
        $ids = $client->uploadFromUrls(15211, ['https://example.com/a.png']);

        $this->assertSame([915], $ids);
        Http::assertSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/media/upload/url'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request['workspace_id'] === 15211
            && $request['urls'] === ['https://example.com/a.png']);
    }

    public function test_upload_from_urls_sends_the_idempotency_key_when_provided(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
            ], 200),
        ]);

        $client = $this->clientWithKey();
        $client->uploadFromUrls(15211, ['https://example.com/a.png'], 'media-operation-group');

        Http::assertSent(fn ($request) => $request->hasHeader(
            'Idempotency-Key',
            'media-operation-group',
        ));
    }

    public function test_create_post_returns_post_payload(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response([
                'id' => 42,
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-25T12:00:00+06:00',
            ], 201),
        ]);

        $body = [
            'workspace_id' => 15211,
            'content' => [['text' => 'Hello', 'media' => [915]]],
            'accounts' => [['id' => 100, 'settings' => []]],
            'schedule_type' => 'schedule',
        ];

        $client = $this->clientWithKey();
        $result = $client->createPost($body);

        $this->assertSame(42, $result['id']);
        $this->assertSame('scheduled', $result['status']);
        $this->assertSame('2026-08-25T12:00:00+06:00', $result['scheduled_at']);
        Http::assertSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/posts'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request['workspace_id'] === 15211);
    }

    public function test_create_post_sends_the_idempotency_key_when_provided(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['id' => 42], 201),
        ]);

        $client = $this->clientWithKey();
        $client->createPost(['workspace_id' => 15211], 'publish-operation-group');

        Http::assertSent(fn ($request) => $request->hasHeader(
            'Idempotency-Key',
            'publish-operation-group',
        ));
    }

    public function test_list_workspaces_returns_ids_with_names(): void
    {
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

        $client = $this->clientWithKey();
        $workspaces = $client->listWorkspaces();

        $this->assertSame([
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
        ], $workspaces);
        Http::assertSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/workspaces'
            && $request->hasHeader('Authorization', 'Bearer test-api-key'));
    }

    public function test_list_workspaces_falls_back_to_slug_when_name_is_missing(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/workspaces' => Http::response([
                [
                    'id' => 853,
                    'slug' => 'english-main',
                ],
            ], 200),
        ]);

        $client = $this->clientWithKey();

        $this->assertSame([
            ['id' => '853', 'name' => 'english-main', 'accounts' => []],
        ], $client->listWorkspaces());
    }

    public function test_list_accounts_filters_by_workspace_id(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/accounts' => Http::response([
                'data' => [
                    [
                        'id' => 136,
                        'workspace_id' => 15211,
                        'platform' => 'facebook',
                        'username' => 'harun',
                    ],
                    [
                        'id' => 245,
                        'workspace_id' => 853,
                        'platform' => 'twitter',
                        'username' => 'other',
                    ],
                ],
            ], 200),
        ]);

        $client = $this->clientWithKey();
        $accounts = $client->listAccounts(15211);

        $this->assertCount(1, $accounts);
        $this->assertSame(136, $accounts[0]['id']);
        $this->assertSame('facebook', $accounts[0]['platform']);
        Http::assertSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/accounts'
            && $request->hasHeader('Authorization', 'Bearer test-api-key'));
    }

    public function test_get_post_returns_the_live_payload(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts/130052' => Http::response([
                'id' => 130052,
                'status' => 'PUBLISHED',
                'scheduled_at' => '2026-08-26T09:00:00+06:00',
            ], 200),
        ]);

        $client = $this->clientWithKey();
        $post = $client->getPost(130052);

        $this->assertSame(130052, $post['id']);
        $this->assertSame('PUBLISHED', $post['status']);
        Http::assertSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/posts/130052');
    }

    public function test_non_success_response_throws_postsyncer_exception(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'message' => 'Invalid workspace',
            ], 422),
        ]);

        $client = $this->clientWithKey();

        $this->expectException(PostsyncerException::class);
        $this->expectExceptionMessage('PostSyncer API error 422: Invalid workspace');

        $client->uploadFromUrls(15211, ['https://example.com/a.png']);
    }

    public function test_a_connection_failure_is_an_unknown_outcome(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::failedConnection('timed out'),
        ]);

        $client = $this->clientWithKey();

        try {
            $client->createPost(['workspace_id' => 15211]);
            $this->fail('A PostSyncer connection failure should throw.');
        } catch (PostsyncerException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertTrue($exception->outcomeUnknown);
            $this->assertStringContainsString('Could not reach PostSyncer', $exception->getMessage());
        }
    }

    public function test_missing_api_key_throws_postsyncer_exception(): void
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        $client = new PostsyncerClient(PostsyncerConfig::fromWorkspace($workspace));

        $this->expectException(PostsyncerException::class);
        $this->expectExceptionMessage('PostSyncer API key is not configured');

        $client->listAccounts(15211);
    }
}
