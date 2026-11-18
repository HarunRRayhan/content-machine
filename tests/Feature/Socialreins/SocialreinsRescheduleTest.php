<?php

namespace Tests\Feature\Socialreins;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsResults;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakesPublicDns;
use Tests\TestCase;

/** Reschedule a post or video published through SocialReins: SocialReins posts p1 and p2. */
class SocialreinsRescheduleTest extends TestCase
{
    use FakesPublicDns, RefreshDatabase;

    private const SET = '11111111-1111-4111-8111-111111111111';

    private Workspace $workspace;

    private string $token;

    private CarbonImmutable $source;

    private CarbonImmutable $target;

    /** @var array<string, array<string, mixed>> fake brain posts by id */
    private array $brain = [];

    /** @var list<string> brain post ids whose PUT answers 409 not_editable */
    private array $brainRefuses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolveHostsTo();

        $this->workspace = Workspace::factory()->create();
        $this->token = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('test client'),
        )['plaintext'];

        SocialreinsConfig::write($this->workspace, [
            'base_url' => 'https://brain.test/v1',
            'default_language' => 'bangla',
            'languages' => ['bangla' => ['set_id' => self::SET, 'token' => 'sr_set_live_bangla', 'platforms' => ['linkedin', 'threads']]],
        ]);

        $now = CarbonImmutable::now('Asia/Dhaka')->setSecond(0);
        $this->source = $now->addDays(2)->setTime(9, 0);
        $this->target = $now->addDays(3)->setTime(18, 30);

        foreach (['p1' => 'linkedin', 'p2' => 'threads'] as $id => $platform) {
            $this->brain[$id] = [
                'id' => $id, 'social_set_id' => self::SET, 'platform' => $platform, 'body' => "hi {$platform}",
                'media' => [], 'thread' => [], 'first_comment' => null, 'cover' => null, 'tiktok' => null, 'youtube' => null,
                'status' => 'scheduled', 'scheduled_at' => $this->source->utc()->toIso8601String(), 'targets' => [],
            ];
        }
        $this->brain['p1']['first_comment'] = 'source link';
        $this->brain['p2']['thread'] = [['body' => 'part 2', 'media' => ['https://example.com/a.jpg']]];

        Http::fake(fn (Request $request) => $this->answer($request));
        Http::preventStrayRequests();
    }

    private function answer(Request $request): mixed
    {
        $method = $request->method();
        $url = $request->url();

        if (str_starts_with($url, 'https://brain.test/v1/posts/')) {
            $id = basename($url);
            if ($method === 'DELETE') {
                $this->brain[$id]['status'] = 'deleted';

                return Http::response(null, 204);
            }
            if ($method === 'PUT') {
                if (in_array($id, $this->brainRefuses, true)) {
                    return Http::response(['error' => 'not_editable'], 409);
                }
                // Brain replaces the post with the body: a field left out is lost.
                $this->brain[$id] = [...$request->data(), 'id' => $id, 'status' => 'scheduled', 'targets' => []];
            }

            return Http::response($this->brain[$id]);
        }

        return Http::response(['error' => 'not_stubbed'], 404);
    }

    /**
     * A post or video scheduled through SocialReins, as EnqueueSocialreinsPublishAction and the settle leave it.
     *
     * @param  class-string<Post|Video>  $model
     */
    private function socialreinsOnly(string $model = Post::class): Post|Video
    {
        $post = $model::factory()->for($this->workspace)->create([
            'status' => 'scheduled',
            'publish_state' => 'succeeded',
            'publish_progress' => ['driver' => 'socialreins', 'state' => 'succeeded', 'options' => ['platforms' => ['linkedin', 'threads']]],
        ]);

        $result = fn (string $platform): array => ['platform' => $platform, 'status' => 'scheduled', 'error' => null, 'at' => 0, 'targets' => []];
        SocialreinsGroup::query()->create([
            'workspace_id' => $this->workspace->id, 'subject_type' => $post instanceof Video ? 'video' : 'post', 'subject_id' => $post->id,
            'language' => 'bangla', 'sequence' => 1, 'idempotency_key' => 'k', 'state' => 'created',
            'request' => json_encode(['scheduled_at' => $this->source->utc()->toIso8601String()]),
            'group_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'expected_targets' => 2,
            'results' => ['p1' => $result('linkedin'), 'p2' => $result('threads')],
        ]);

        return $post;
    }

    /** @var array<string, mixed> a result moved between rows by a test */
    private array $moved = [];

    private function reschedule(Post|Video $post): TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/v1/'.($post instanceof Video ? 'videos' : 'posts')."/{$post->human_id}/reschedule", [
            'when' => $this->target->toIso8601String(),
        ]);
    }

    /** @return list<string> */
    private function brainPuts(): array
    {
        return Http::recorded()->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $r): bool => $r->method() === 'PUT' && str_starts_with($r->url(), 'https://brain.test/'))
            ->map(fn (Request $r): string => basename($r->url()))->values()->all();
    }

    private function storedAt(Post|Video $post, string $postId): ?string
    {
        return SocialreinsGroup::query()->where('subject_id', $post->id)->first()->results[$postId]['scheduled_at'] ?? null;
    }

    public function test_reschedule_moves_a_post(): void
    {
        $post = $this->socialreinsOnly();

        $this->reschedule($post)->assertOk();

        $target = $this->target->utc()->format('Y-m-d\TH:i:s\Z');
        $this->assertSame(['p1', 'p2'], $this->brainPuts());
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT' && $r->url() === 'https://brain.test/v1/posts/p1'
            && $r['scheduled_at'] === $target && $r['first_comment'] === 'source link');
        $this->assertSame($target, $this->storedAt($post, 'p1'));
        $this->assertSame($target, $this->storedAt($post, 'p2'));
        $post->refresh();
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('succeeded', $post->publish_state);
    }

    public function test_reschedule_passes_over_a_platform_socialreins_left_out(): void
    {
        $post = $this->socialreinsOnly();
        SocialreinsGroup::query()->where('subject_id', $post->id)->sole()->mutateResults(
            fn (array $results): array => $results + SocialreinsResults::fromSkipped([['platform' => 'x', 'problems' => [['code' => 'too_long']]]], time()),
        );

        $this->reschedule($post)->assertOk();

        $this->assertSame(['p1', 'p2'], $this->brainPuts());
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'skipped'));
    }

    /** @param  array<string, mixed>  $values */
    private function addRow(Post $post, int $sequence, string $state, array $values = []): SocialreinsGroup
    {
        return SocialreinsGroup::query()->create([
            'workspace_id' => $this->workspace->id, 'subject_type' => 'post', 'subject_id' => $post->id,
            'language' => 'bangla', 'sequence' => $sequence, 'idempotency_key' => "k{$sequence}", 'state' => $state,
            'request' => json_encode(['scheduled_at' => $this->source->utc()->toIso8601String()]),
            ...$values,
        ]);
    }

    public function test_reschedule_moves_a_retried_left_out_platform_with_the_rest_and_passes_over_a_refused_retry(): void
    {
        $post = $this->socialreinsOnly();
        // Threads was left out of the first group, sent by a retry in its own group; a later retry was refused.
        SocialreinsGroup::query()->where('subject_id', $post->id)->sole()->mutateResults(function (array $results): array {
            $threads = $results['p2'];
            unset($results['p2']);
            $this->moved = $threads;

            return $results + SocialreinsResults::fromSkipped([['platform' => 'threads', 'problems' => [['code' => 'too_long']]]], time());
        });
        $retry = $this->addRow($post, 2, 'created', ['group_id' => 'aaaaaaaa-0000-4000-8000-000000000002', 'expected_targets' => 1, 'results' => ['p2' => $this->moved]]);
        $this->addRow($post, 3, 'rejected', ['error' => 'SocialReins answered 422.']);

        $this->reschedule($post)->assertOk();

        $this->assertSame(['p1', 'p2'], $this->brainPuts());
        $this->assertSame($this->target->utc()->format('Y-m-d\TH:i:s\Z'), $retry->fresh()->results['p2']['scheduled_at']);

        // An unanswered request may still have created a group: that blocks, as before.
        $this->addRow($post, 4, 'pending');
        $this->reschedule($post)->assertStatus(422);
    }

    public function test_reschedule_leaves_an_older_group_the_newest_one_replaced_alone(): void
    {
        $post = $this->socialreinsOnly();
        $this->addRow($post, 0, 'created', ['group_id' => 'aaaaaaaa-0000-4000-8000-000000000000', 'expected_targets' => 1, 'results' => [
            'old1' => ['platform' => 'facebook', 'status' => 'failed', 'error' => 'boom', 'at' => 0, 'targets' => []],
        ]]);

        $this->reschedule($post)->assertOk();

        $this->assertSame(['p1', 'p2'], $this->brainPuts());
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'old1'));
    }

    public function test_reschedule_moves_a_video(): void
    {
        $video = $this->socialreinsOnly(Video::class);

        $this->reschedule($video)->assertOk();

        $target = $this->target->utc()->format('Y-m-d\TH:i:s\Z');
        $this->assertSame(['p1', 'p2'], $this->brainPuts());
        $this->assertSame($target, $this->storedAt($video, 'p1'));
        $this->assertSame($target, $this->storedAt($video, 'p2'));
    }

    public function test_a_reschedule_refused_by_brain_reports_it_and_a_retry_finishes(): void
    {
        $post = $this->socialreinsOnly();
        $this->brainRefuses = ['p2'];

        $this->reschedule($post)->assertUnprocessable()
            ->assertJsonPath('errors.when.0', fn (string $m): bool => str_contains($m, 'The SocialReins reschedule did not finish')
                && ! str_contains($m, 'PostSyncer') && str_contains($m, 'Moved: bangla linkedin') && str_contains($m, 'bangla threads failed'));

        $this->assertNull($this->storedAt($post, 'p2'));

        $this->brainRefuses = [];
        $this->reschedule($post)->assertOk();

        $this->assertSame(['p1', 'p2', 'p2'], $this->brainPuts());
        $this->assertNotNull($this->storedAt($post, 'p2'));
    }

    public function test_a_reschedule_moves_nothing_when_one_platform_already_went_out(): void
    {
        $post = $this->socialreinsOnly();
        $this->brain['p2']['status'] = 'published';

        $this->reschedule($post)->assertUnprocessable()
            ->assertJsonPath('errors.when.0', fn (string $m): bool => str_contains($m, 'Nothing was rescheduled') && str_contains($m, 'threads is published'));

        $this->assertSame([], $this->brainPuts());
    }

    public function test_a_reschedule_keeps_the_existing_rules(): void
    {
        $post = $this->socialreinsOnly();

        $post->forceFill(['status' => 'posted'])->save();
        $this->reschedule($post)->assertUnprocessable()->assertJsonPath('errors.when.0', 'Only scheduled posts can be rescheduled.');

        $post->forceFill(['status' => 'scheduled', 'publish_state' => 'running'])->save();
        $this->reschedule($post)->assertUnprocessable()->assertJsonPath('errors.when.0', 'A publish is already in progress.');

        $post->forceFill(['publish_state' => 'succeeded'])->save();
        $this->target = CarbonImmutable::now()->subHour();
        $this->reschedule($post)->assertUnprocessable()->assertJsonPath('errors.when.0', 'The new schedule must be in the future.');

        $post->forceFill(['publish_progress' => null])->save();
        $this->reschedule($post)->assertUnprocessable()->assertJsonPath('errors.when.0', 'Only a post published through SocialReins can be rescheduled.');

        // Refused before brain was asked anything.
        Http::assertNothingSent();
    }

    public function test_a_second_reschedule_waits_for_the_running_one(): void
    {
        $this->assertOneRescheduleAtATime($this->socialreinsOnly(), 'post');
    }

    private function assertOneRescheduleAtATime(Post|Video $subject, string $noun): void
    {
        $lease = fn (CarbonImmutable $startedAt): bool => $subject->forceFill([
            'publish_progress' => [...$subject->publish_progress, 'socialreins_reschedule' => ['started_at' => $startedAt->toIso8601String()]],
        ])->save();

        // Another call holds the lease: nothing is read or sent.
        $lease(CarbonImmutable::now()->subMinute());
        $this->reschedule($subject)->assertUnprocessable()
            ->assertJsonPath('errors.when.0', "Another reschedule is already running for this {$noun}.");
        Http::assertNothingSent();

        // A call that died left its lease behind: it expires, and a finished call leaves none.
        $lease(CarbonImmutable::now()->subMinutes(16));
        $this->reschedule($subject)->assertOk()->assertJsonMissingPath('data.publish_progress.socialreins_reschedule');
        $this->assertArrayNotHasKey('socialreins_reschedule', $subject->refresh()->publish_progress);
        $this->assertSame('succeeded', $subject->publish_state);

        // A failed call releases it too.
        $this->target = $this->target->addHour();
        $this->brainRefuses = ['p1'];
        $this->reschedule($subject)->assertUnprocessable();
        $this->assertArrayNotHasKey('socialreins_reschedule', $subject->refresh()->publish_progress);
    }

    public function test_a_video_must_have_a_settled_publish(): void
    {
        $video = $this->socialreinsOnly(Video::class);

        $video->forceFill(['publish_state' => 'failed'])->save();
        $this->reschedule($video)->assertUnprocessable()
            ->assertJsonPath('errors.when.0', 'The video has a publish in progress or an uncertain publish. Reconcile it first.');

        $video->forceFill(['publish_state' => 'succeeded', 'publish_progress' => [...$video->publish_progress, 'state' => 'uncertain']])->save();
        $this->reschedule($video)->assertUnprocessable()
            ->assertJsonPath('errors.when.0', 'The video has a publish in progress or an uncertain publish. Reconcile it first.');

        Http::assertNothingSent();
    }
}
