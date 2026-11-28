<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Actions\Postsyncer\EnqueuePostPublishAction;
use App\Actions\Postsyncer\PublishPostAction;
use App\Actions\Postsyncer\UpdatePostsyncerSettingsAction;
use App\Actions\Socialreins\PublishToSocialreinsAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Jobs\PublishPostJob;
use App\Jobs\PublishSocialreinsJob;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakesPublicDns;
use Tests\TestCase;

/** A split publish whose second PostSyncer create has an unknown outcome, resolved by skipping it. */
class PostsSkipUncertainGroupTest extends TestCase
{
    use FakesPublicDns, RefreshDatabase;

    private const SR_URL = 'https://brain.test/v1/post-groups';

    private const PS_URL = 'https://postsyncer.com/api/v1/posts';

    private string $token;

    private Workspace $workspace;

    private Post $post;

    private int $psCreates = 0;

    private int $srCreates = 0;

    /** @var array<int, array<string, mixed>> */
    private array $remote = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolveHostsTo();
        Queue::fake();

        $this->workspace = Workspace::factory()->create();
        $this->token = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('test client'),
        )['plaintext'];

        PostsyncerConfig::write($this->workspace, [
            'api_key' => 'k',
            'publish_enabled' => true,
            'default_language' => 'english',
            'languages' => [
                'english' => ['workspace_id' => '853', 'platforms' => [
                    'facebook' => ['account_id' => 100, 'handle' => '@fb'],
                    'linkedin' => ['account_id' => 102, 'handle' => '@li'],
                ]],
                'bangla' => ['workspace_id' => '15211', 'platforms' => [
                    'facebook' => ['account_id' => 200, 'handle' => '@fb'],
                    'linkedin' => ['account_id' => 202, 'handle' => '@li'],
                ]],
            ],
            'post_types' => ['platforms' => [
                'facebook' => ['text' => 'on'],
                'linkedin' => ['text' => 'on'],
            ]],
        ]);
        SocialreinsConfig::write($this->workspace, [
            'publish_driver' => 'socialreins',
            'base_url' => 'https://brain.test/v1',
            'default_language' => 'english',
            'languages' => [
                'english' => ['set_id' => '11111111-1111-4111-8111-111111111111', 'token' => 'sr_en', 'platforms' => ['facebook']],
                'bangla' => ['set_id' => '22222222-2222-4222-8222-222222222222', 'token' => 'sr_bn', 'platforms' => ['facebook']],
            ],
        ]);
        $this->workspace = $this->workspace->fresh();

        $this->post = Post::factory()->for($this->workspace)->create([
            'language' => 'en',
            'platforms' => ['facebook', 'linkedin'],
            'captions' => [
                'English' => ['facebook' => ['caption' => 'EN fb'], 'linkedin' => ['caption' => 'EN li']],
                'Bangla' => ['facebook' => ['caption' => 'BN fb'], 'linkedin' => ['caption' => 'BN li']],
            ],
            'image_drive_urls' => [],
            'status' => 'ready',
        ]);

        $this->fakeApis();
    }

    /** The first PostSyncer create succeeds, the second one answers 500: its outcome is unknown. */
    private function fakeApis(bool $secondCreateFails = true): void
    {
        $this->psCreates = 0;
        $this->srCreates = 0;
        $this->remote = [];

        $this->fakeHttp([
            self::SR_URL => function () {
                $n = ++$this->srCreates;

                return Http::response([
                    'group_id' => sprintf('aaaaaaaa-0000-4000-8000-%012d', $n),
                    'expected_targets' => 1,
                    'posts' => [['id' => sprintf('bbbbbbbb-0000-4000-8000-%012d', $n), 'platform' => 'facebook', 'status' => 'scheduled', 'error' => null, 'targets' => []]],
                ], 201);
            },
            self::PS_URL => function (Request $request) use ($secondCreateFails) {
                $this->psCreates++;

                if ($secondCreateFails && $this->psCreates === 2) {
                    return Http::response(['message' => 'boom'], 500);
                }

                $id = 40 + $this->psCreates;
                $body = $request->data();
                $this->remote[$id] = [
                    'id' => $id,
                    'workspace_id' => (int) $body['workspace_id'],
                    'content' => $body['content'],
                    'platforms' => array_map(fn (array $account): array => [
                        'platform' => 'linkedin',
                        'account_id' => $account['id'],
                        'settings' => $account['settings'] ?? [],
                    ], $body['accounts']),
                    'status' => 'PUBLISHED',
                ];

                return Http::response($this->remote[$id], 201);
            },
            self::PS_URL.'/*' => fn (Request $request) => Http::response(
                $this->remote[(int) basename($request->url())] ?? ['error' => 'missing'],
                isset($this->remote[(int) basename($request->url())]) ? 200 : 404,
            ),
        ]);
    }

    private function runQueued(): void
    {
        $ran = [];
        do {
            $jobs = collect(Queue::pushedJobs())->flatten(1)->pluck('job')
                ->reject(fn (object $job): bool => in_array(spl_object_id($job), $ran, true));
            foreach ($jobs as $job) {
                $ran[] = spl_object_id($job);
                match (true) {
                    $job instanceof PublishPostJob => $job->handle(app(PublishPostAction::class)),
                    $job instanceof PublishSocialreinsJob => $job->handle(app(PublishToSocialreinsAction::class)),
                    default => null,
                };
            }
        } while ($jobs->isNotEmpty());
    }

    private function uncertainPost(): Post
    {
        app(EnqueuePostPublishAction::class)->handle($this->post, $this->workspace, []);
        $this->runQueued();

        $post = $this->post->fresh();
        $this->assertSame('uncertain', $post->publish_progress['state']);
        $this->assertSame('creating', $post->publish_progress['current']['phase']);

        return $post;
    }

    private function skip(Post $post, ?string $groupKey = null, string $confirm = 'skip')
    {
        return $this->withToken($this->token)->postJson('/api/v1/posts/'.$post->human_id.'/publish/skip-uncertain-group', [
            'group_key' => $groupKey ?? $post->publish_progress['current']['group_key'],
            'confirm' => $confirm,
            'reason' => 'Verified in the PostSyncer UI: cannot tell if it exists; Harun approved skipping.',
        ]);
    }

    public function test_skipping_the_uncertain_group_records_it_and_clears_the_blocker(): void
    {
        $post = $this->uncertainPost();
        $current = $post->publish_progress['current'];

        $this->skip($post)
            ->assertOk()
            ->assertJsonPath('data.publish_state', 'failed')
            ->assertJsonPath('data.publish_progress.state', 'failed')
            ->assertJsonPath('data.publish_progress.current', null)
            ->assertJsonPath('data.publish_progress.skipped_groups.0.status', 'skipped_unknown')
            ->assertJsonPath('data.publish_progress.skipped_groups.0.group_key', $current['group_key'])
            ->assertJsonPath('data.publish_progress.skipped_groups.0.idempotency_key', $current['idempotency_key']);

        $progress = $post->fresh()->publish_progress;
        $this->assertCount(1, $progress['completed_groups']);
        $this->assertCount(1, $progress['skipped_groups']);
        $this->assertSame($post->publish_progress['options'], $progress['options']);
        $this->assertSame($post->publish_progress['plan_hash'], $progress['plan_hash']);
    }

    public function test_a_wrong_group_key_is_refused_and_changes_nothing(): void
    {
        $post = $this->uncertainPost();

        $this->skip($post, 'not-the-group')->assertUnprocessable()->assertJsonValidationErrors('publish');
        $this->skip($post, null, 'yes')->assertUnprocessable()->assertJsonValidationErrors('confirm');

        $this->assertSame($post->publish_progress, $post->fresh()->publish_progress);
    }

    public function test_a_post_that_is_not_uncertain_is_refused(): void
    {
        $this->fakeApis(secondCreateFails: false);
        app(EnqueuePostPublishAction::class)->handle($this->post, $this->workspace, []);
        $this->runQueued();
        $post = $this->post->fresh();
        $this->assertNotSame('uncertain', $post->publish_progress['state']);

        $this->skip($post, 'any-group')->assertUnprocessable()->assertJsonValidationErrors('publish');
    }

    public function test_a_second_call_is_idempotent(): void
    {
        $post = $this->uncertainPost();
        $key = $post->publish_progress['current']['group_key'];

        $this->skip($post, $key)->assertOk();
        $this->skip($post, $key)->assertOk();

        $this->assertCount(1, $post->fresh()->publish_progress['skipped_groups']);
    }

    public function test_the_retry_skips_the_group_and_continues_to_socialreins(): void
    {
        $post = $this->uncertainPost();
        $this->skip($post)->assertOk();
        $this->assertSame(2, $this->psCreates);

        // The caller may name the SocialReins platforms: the original options win on resume.
        app(EnqueuePostPublishAction::class)->handle($post->fresh(), $this->workspace, ['platforms' => ['facebook']]);
        $this->runQueued();

        $post = $post->fresh();
        $this->assertSame(2, $this->psCreates, 'the skipped group must not be created again');
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('socialreins', $post->publish_progress['driver']);
        $this->assertCount(1, $post->postsyncer['groups']);
        $this->assertSame('skipped_unknown', $post->publish_progress['split']['skipped_groups'][0]['status']);
        $this->assertSame(2, $this->srCreates);
    }

    public function test_a_running_publish_is_refused(): void
    {
        $post = $this->uncertainPost();
        $post->forceFill(['publish_state' => 'running'])->save();

        $this->skip($post->fresh())->assertUnprocessable()->assertJsonValidationErrors('publish');
        $this->assertArrayNotHasKey('skipped_groups', $post->fresh()->publish_progress);
    }

    /** After a skip only `skipped_groups` records the external progress: edits and settings must still be locked. */
    public function test_a_skip_still_locks_post_edits_and_postsyncer_settings(): void
    {
        $post = $this->uncertainPost();
        $this->skip($post)->assertOk();
        $before = $post->fresh()->publish_progress;
        $post->forceFill(['publish_progress' => [...$before, 'completed_groups' => []]])->save();

        $this->withToken($this->token)->patchJson('/api/v1/posts/'.$post->human_id, [
            'publish_state' => 'idle',
            'publish_error' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('publish_state');

        try {
            app(UpdatePostsyncerSettingsAction::class)->handle($this->workspace, ['publish_enabled' => true, 'api_key' => 'other']);
            $this->fail('Settings must be locked while a skipped group is pending.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('postsyncer', $e->errors());
        }

        $this->assertSame('failed', $post->fresh()->publish_state);
        $this->assertSame($before['skipped_groups'], $post->fresh()->publish_progress['skipped_groups']);
    }
}
