<?php

namespace Tests\Unit\Support\Postsyncer;

use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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

    public function test_upload_from_urls_rejects_a_partial_response(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => 915]],
                'count_stored' => 1,
            ], 200),
        ]);

        $client = $this->clientWithKey();

        $this->expectException(PostsyncerException::class);
        $this->expectExceptionMessage('incomplete media upload response');

        $client->uploadFromUrls(15211, [
            'https://example.com/a.png',
            'https://example.com/b.png',
        ]);
    }

    public function test_upload_from_urls_rejects_a_media_item_without_a_valid_id(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/media/upload/url' => Http::response([
                'media' => [['id' => null]],
                'count_stored' => 1,
            ], 200),
        ]);

        $client = $this->clientWithKey();

        $this->expectException(PostsyncerException::class);
        $this->expectExceptionMessage('without a valid id');

        $client->uploadFromUrls(15211, ['https://example.com/a.png']);
    }

    /**
     * @return list<string>
     */
    private function mediaTempFiles(): array
    {
        return glob(sys_get_temp_dir().'/cm-media-*') ?: [];
    }

    public function test_upload_files_from_urls_downloads_then_uploads_each_file_in_order(): void
    {
        $before = $this->mediaTempFiles();
        Http::fake([
            'example.com/video.mp4' => fn () => Http::response('video-bytes', 200),
            'example.com/cover.png' => fn () => Http::response('cover-bytes', 200),
            'upload.postsyncer.com/api/v1/media/upload/file' => Http::sequence()
                ->push(['media' => ['id' => 915]], 201)
                ->push(['media' => ['id' => 916]], 201),
        ]);

        $ids = $this->clientWithKey()->uploadFilesFromUrls(15211, [
            'https://example.com/video.mp4',
            'https://example.com/cover.png',
        ], 'op:0:group:media');

        $this->assertSame([915, 916], $ids);
        $uploads = Http::recorded(fn ($request): bool => $request->url()
            === 'https://upload.postsyncer.com/api/v1/media/upload/file');
        $this->assertCount(2, $uploads);
        $first = $uploads->first()[0];
        $this->assertTrue($first->isMultipart());
        $this->assertTrue($first->hasHeader('Authorization', 'Bearer test-api-key'));
        $this->assertSame(['op:0:group:media:0'], $first->header('Idempotency-Key'));
        $this->assertSame(['op:0:group:media:1'], $uploads->last()[0]->header('Idempotency-Key'));
        $parts = collect($first->data())->keyBy('name');
        $this->assertSame('15211', (string) $parts['workspace_id']['contents']);
        $this->assertSame('video-bytes', (string) $parts['file']['contents']);
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://postsyncer.com/'));
        $this->assertSame($before, $this->mediaTempFiles());
    }

    public function test_upload_files_from_urls_marks_a_lost_upload_response_uncertain(): void
    {
        $before = $this->mediaTempFiles();
        Http::fake([
            'example.com/video.mp4' => fn () => Http::response('video-bytes', 200),
            'upload.postsyncer.com/api/v1/media/upload/file' => fn () => throw new ConnectionException('timed out'),
        ]);

        try {
            $this->clientWithKey()->uploadFilesFromUrls(15211, ['https://example.com/video.mp4']);
            $this->fail('Expected the lost upload response to throw.');
        } catch (PostsyncerException $exception) {
            $this->assertTrue($exception->outcomeUnknown);
            $this->assertFalse($exception->responseReceived);
            $this->assertFalse($exception->safeToRetry);
        }

        $this->assertSame($before, $this->mediaTempFiles());
    }

    public function test_upload_files_from_urls_treats_a_first_file_rejection_as_safe_to_retry(): void
    {
        Http::fake([
            'example.com/video.mp4' => fn () => Http::response('video-bytes', 200),
            'upload.postsyncer.com/api/v1/media/upload/file' => Http::response(['message' => 'Unsupported file'], 422),
        ]);

        try {
            $this->clientWithKey()->uploadFilesFromUrls(15211, ['https://example.com/video.mp4']);
            $this->fail('Expected the rejection to throw.');
        } catch (PostsyncerException $exception) {
            $this->assertSame(422, $exception->getCode());
            $this->assertTrue($exception->responseReceived);
            $this->assertFalse($exception->outcomeUnknown);
            $this->assertTrue($exception->safeToRetry);
        }
    }

    public function test_upload_files_from_urls_keeps_a_rejection_after_a_stored_file_unsafe(): void
    {
        Http::fake([
            'example.com/*' => fn () => Http::response('bytes', 200),
            'upload.postsyncer.com/api/v1/media/upload/file' => Http::sequence()
                ->push(['media' => ['id' => 915]], 201)
                ->push(['message' => 'Unsupported file'], 422),
        ]);

        try {
            $this->clientWithKey()->uploadFilesFromUrls(15211, [
                'https://example.com/video.mp4',
                'https://example.com/cover.png',
            ]);
            $this->fail('Expected the rejection to throw.');
        } catch (PostsyncerException $exception) {
            $this->assertFalse($exception->safeToRetry);
        }
    }

    public function test_upload_files_from_urls_download_failure_sends_nothing_to_postsyncer(): void
    {
        $before = $this->mediaTempFiles();
        Http::fake([
            'example.com/video.mp4' => fn () => Http::response('video-bytes', 200),
            'example.com/cover.png' => Http::response('missing', 404),
            'upload.postsyncer.com/*' => Http::response(['media' => ['id' => 915]], 201),
        ]);

        try {
            $this->clientWithKey()->uploadFilesFromUrls(15211, [
                'https://example.com/video.mp4',
                'https://example.com/cover.png',
            ]);
            $this->fail('Expected the download failure to throw.');
        } catch (PostsyncerException $exception) {
            $this->assertStringContainsString('Could not download media', $exception->getMessage());
            $this->assertTrue($exception->safeToRetry);
            $this->assertTrue($exception->responseReceived);
            $this->assertFalse($exception->outcomeUnknown);
        }

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'postsyncer.com'));
        $this->assertSame($before, $this->mediaTempFiles());
    }

    public function test_upload_files_from_urls_rejects_an_html_page_instead_of_media(): void
    {
        Http::fake([
            'example.com/video.mp4' => fn () => Http::response('<!DOCTYPE html><html><body>Sign in</body></html>', 200),
        ]);

        $this->expectException(PostsyncerException::class);
        $this->expectExceptionMessage('HTML page');

        $this->clientWithKey()->uploadFilesFromUrls(15211, ['https://example.com/video.mp4']);
    }

    public function test_upload_files_from_urls_rejects_files_over_the_size_cap_before_uploading(): void
    {
        $before = $this->mediaTempFiles();
        Http::fake([
            'example.com/video.mp4' => fn () => Http::response(str_repeat('x', 11), 200),
        ]);
        $workspace = Workspace::factory()->create(['settings' => []]);
        PostsyncerConfig::write($workspace, ['api_key' => 'test-api-key']);
        $client = new class(PostsyncerConfig::fromWorkspace($workspace->refresh())) extends PostsyncerClient
        {
            public const MAX_FILE_UPLOAD_BYTES = 10;
        };

        try {
            $client->uploadFilesFromUrls(15211, ['https://example.com/video.mp4']);
            $this->fail('Expected the size cap to throw.');
        } catch (PostsyncerException $exception) {
            $this->assertStringContainsString('upload limit', $exception->getMessage());
            $this->assertTrue($exception->safeToRetry);
        }

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'postsyncer.com'));
        $this->assertSame($before, $this->mediaTempFiles());
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

    public function test_update_post_sends_the_full_payload_with_put(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts/42' => Http::response([
                'id' => 42,
                'status' => 'scheduled',
                'scheduled_at' => '2026-08-25 12:29',
            ], 200),
        ]);

        $body = [
            'workspace_id' => 15211,
            'content' => [['text' => 'Hello', 'media' => [915]]],
            'accounts' => [['id' => 100, 'settings' => []]],
            'schedule_type' => 'schedule',
            'schedule_for' => [
                'date' => '2026-08-25',
                'time' => '12:29',
                'timezone' => 'Asia/Dhaka',
            ],
        ];

        $client = $this->clientWithKey();
        $result = $client->updatePost(42, $body);

        $this->assertSame(42, $result['id']);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://postsyncer.com/api/v1/posts/42'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request['schedule_for']['time'] === '12:29');
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

    public function test_get_post_with_account_details_enriches_platform_rows_from_analytics(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts/130052' => Http::response([
                'data' => [
                    'id' => 130052,
                    'status' => 'PARTIALLY_FAILED',
                    'platforms' => [
                        ['platform' => 'tiktok', 'status' => 'FAILED'],
                    ],
                ],
            ], 200),
            'postsyncer.com/api/v1/analytics/posts/130052' => Http::response([
                'data' => [
                    'accounts' => [
                        [
                            'account_id' => 3508,
                            'platform' => 'tiktok',
                            'account' => ['id' => 3508],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = $this->clientWithKey();
        $post = $client->getPostWithAccountDetails(130052);

        $this->assertSame(3508, $post['platforms'][0]['account_id']);
        $this->assertSame(3508, $post['platforms'][0]['account']['id']);
        Http::assertSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/analytics/posts/130052');
    }

    public function test_get_post_url_encodes_the_remote_id(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts/*' => Http::response([
                'id' => 42,
                'status' => 'PUBLISHED',
            ], 200),
        ]);

        $this->clientWithKey()->getPost('42/other');

        Http::assertSent(fn ($request) => $request->url() === 'https://postsyncer.com/api/v1/posts/42%2Fother');
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
