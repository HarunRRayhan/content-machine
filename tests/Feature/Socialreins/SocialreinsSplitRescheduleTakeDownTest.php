<?php

namespace Tests\Feature\Socialreins;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Socialreins\SocialreinsConfig;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakesPublicDns;
use Tests\TestCase;

/** Reschedule and take down both halves of a split post: PostSyncer group 42, SocialReins posts p1 and p2. */
class SocialreinsSplitRescheduleTakeDownTest extends TestCase
{
    use FakesPublicDns, RefreshDatabase;

    private const SET = '11111111-1111-4111-8111-111111111111';

    private Workspace $workspace;

    private string $token;

    private CarbonImmutable $source;

    private CarbonImmutable $target;

    /** Fake PostSyncer post 42: its time, status, and whether it was deleted. */
    private CarbonImmutable $psAt;

    private string $psStatus = 'SCHEDULED';

    private bool $psDeleted = false;

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

        PostsyncerConfig::write($this->workspace, [
            'api_key' => 'k',
            'publish_enabled' => true,
            'languages' => ['english' => ['workspace_id' => '853', 'platforms' => []], 'bangla' => ['workspace_id' => '15211', 'platforms' => []]],
        ]);
        SocialreinsConfig::write($this->workspace, [
            'publish_driver' => 'socialreins',
            'base_url' => 'https://brain.test/v1',
            'default_language' => 'bangla',
            'languages' => ['bangla' => ['set_id' => self::SET, 'token' => 'sr_set_live_bangla', 'platforms' => ['linkedin', 'threads']]],
        ]);

        $now = CarbonImmutable::now('Asia/Dhaka')->setSecond(0);
        $this->source = $now->addDays(2)->setTime(9, 0);
        $this->target = $now->addDays(3)->setTime(18, 30);
        $this->psAt = $this->source;

        foreach (['p1' => 'linkedin', 'p2' => 'threads'] as $id => $platform) {
            $this->brain[$id] = [
                'id' => $id, 'social_set_id' => self::SET, 'platform' => $platform, 'body' => "hi {$platform}",
                'media' => [], 'thread' => [], 'first_comment' => null, 'cover' => null, 'tiktok' => null, 'youtube' => null,
                'status' => 'scheduled', 'scheduled_at' => $this->source->utc()->toIso8601String(), 'targets' => [],
            ];
        }

        Http::fake(fn (Request $request) => $this->answer($request));
        Http::preventStrayRequests();
    }

    private function answer(Request $request): mixed
    {
        $method = $request->method();
        $url = $request->url();

        if ($url === 'https://postsyncer.com/api/v1/posts/42') {
            if ($this->psDeleted) {
                return Http::response(['message' => 'Not found'], 404);
            }
            if ($method === 'DELETE') {
                $this->psDeleted = true;

                return Http::response(['message' => 'Post deleted successfully']);
            }
            if ($method === 'PUT') {
                $this->psAt = CarbonImmutable::parse($request['schedule_for']['date'].' '.$request['schedule_for']['time'], 'Asia/Dhaka');
            }

            return Http::response($this->psRemote());
        }

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
                $this->brain[$id] = [...$this->brain[$id], ...$request->data()];
            }

            return Http::response($this->brain[$id]);
        }

        return Http::response(['error' => 'not_stubbed'], 404);
    }

    /** @return array<string, mixed> */
    private function psRemote(): array
    {
        return [
            'id' => 42,
            'workspace_id' => 15211,
            'content' => [['text' => 'Hello', 'media' => [['id' => 915]]]],
            'platforms' => [['platform' => 'facebook', 'account_id' => 100, 'settings' => ['post_type' => 'POST']]],
            'status' => $this->psStatus,
            'scheduled_at' => $this->psAt->format('Y-m-d H:i'),
        ];
    }

    private function splitPost(): Post
    {
        $payload = [
            'workspace_id' => 15211,
            'content' => [['text' => 'Hello', 'media' => [915]]],
            'accounts' => [['id' => 100, 'settings' => ['post_type' => 'POST', 'caption' => 'Hello']]],
            'schedule_type' => 'schedule',
            'schedule_for' => ['date' => $this->source->format('Y-m-d'), 'time' => $this->source->format('H:i'), 'timezone' => 'Asia/Dhaka'],
        ];
        $group = ['post_id' => '42', 'status' => 'SCHEDULED', 'scheduled_at' => $this->source->format('Y-m-d H:i'), 'platforms' => ['facebook'], 'language' => 'bangla'];

        $post = Post::factory()->for($this->workspace)->create([
            'status' => 'scheduled',
            'publish_state' => 'succeeded',
            'postsyncer' => ['groups' => [$group]],
            // As ContinueSplitOnSocialreinsAction leaves it.
            'publish_progress' => [
                'driver' => 'socialreins',
                'state' => 'failed',
                'options' => ['platforms' => ['linkedin', 'threads']],
                'split' => [
                    'postsyncer' => ['facebook'],
                    'socialreins' => ['platforms' => ['linkedin', 'threads']],
                    'completed_groups' => [[...$group, 'index' => 0, 'expected_payload' => $payload]],
                ],
            ],
        ]);

        $result = fn (string $platform): array => ['platform' => $platform, 'status' => 'scheduled', 'error' => null, 'at' => 0, 'targets' => []];
        SocialreinsGroup::query()->create([
            'workspace_id' => $this->workspace->id, 'subject_type' => 'post', 'subject_id' => $post->id,
            'language' => 'bangla', 'sequence' => 1, 'idempotency_key' => 'k', 'state' => 'created',
            'request' => json_encode(['scheduled_at' => $this->source->utc()->toIso8601String()]),
            'group_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'expected_targets' => 2,
            'results' => ['p1' => $result('linkedin'), 'p2' => $result('threads')],
        ]);

        return $post;
    }

    private function reschedule(Post $post): TestResponse
    {
        return $this->withToken($this->token)->postJson("/api/v1/posts/{$post->human_id}/reschedule", [
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

    private function storedAt(Post $post, string $postId): ?string
    {
        return SocialreinsGroup::query()->where('subject_id', $post->id)->first()->results[$postId]['scheduled_at'] ?? null;
    }

    public function test_reschedule_moves_both_halves(): void
    {
        $post = $this->splitPost();

        $this->reschedule($post)->assertOk();

        $target = $this->target->utc()->format('Y-m-d\TH:i:s\Z');
        $this->assertTrue($this->psAt->equalTo($this->target));
        $this->assertSame(['p1', 'p2'], $this->brainPuts());
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT' && $r->url() === 'https://brain.test/v1/posts/p1'
            && $r['scheduled_at'] === $target && $r['body'] === 'hi linkedin' && $r['social_set_id'] === self::SET && $r['platform'] === 'linkedin');
        $this->assertSame($target, $this->storedAt($post, 'p1'));
        $this->assertSame($target, $this->storedAt($post, 'p2'));
        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertSame('scheduled', $post->status);
        $this->assertSame($this->target->format('Y-m-d H:i'), $post->postsyncer['groups'][0]['scheduled_at']);
        $this->assertSame('socialreins', $post->publish_progress['driver']);
    }

    public function test_reschedule_refuses_before_moving_anything_when_a_socialreins_post_already_went_out(): void
    {
        $post = $this->splitPost();
        $this->brain['p2']['status'] = 'published';

        $this->reschedule($post)->assertUnprocessable()
            ->assertJsonPath('errors.when.0', fn (string $m): bool => str_contains($m, 'Nothing was rescheduled') && str_contains($m, 'threads is published'));

        $this->assertTrue($this->psAt->equalTo($this->source));
        $this->assertSame([], $this->brainPuts());
    }

    public function test_a_socialreins_failure_after_postsyncer_moved_names_the_half_and_a_retry_finishes(): void
    {
        $post = $this->splitPost();
        $this->brainRefuses = ['p2'];

        $this->reschedule($post)->assertUnprocessable()
            ->assertJsonPath('errors.when.0', fn (string $m): bool => str_contains($m, 'PostSyncer half moved')
                && str_contains($m, 'Moved: bangla linkedin') && str_contains($m, 'bangla threads failed'));

        $this->assertTrue($this->psAt->equalTo($this->target));
        $this->assertNotNull($this->storedAt($post, 'p1'));
        $this->assertNull($this->storedAt($post, 'p2'));

        $this->brainRefuses = [];
        $this->reschedule($post)->assertOk();

        // p1 is already at the target and is not sent again.
        $this->assertSame(['p1', 'p2', 'p2'], $this->brainPuts());
        $this->assertNotNull($this->storedAt($post, 'p2'));
    }

    public function test_a_postsyncer_failure_leaves_the_socialreins_half_unchanged(): void
    {
        $post = $this->splitPost();
        $this->psStatus = 'PUBLISHED';

        $this->reschedule($post)->assertUnprocessable()
            ->assertJsonPath('errors.when.0', fn (string $m): bool => str_contains($m, 'PostSyncer half not rescheduled'));

        $this->assertSame([], $this->brainPuts());
    }

    public function test_take_down_removes_both_halves(): void
    {
        $post = $this->splitPost();

        $this->withToken($this->token)->postJson("/api/v1/posts/{$post->human_id}/take-down")
            ->assertOk()->assertJson(['taken_down' => true, 'problems' => []]);

        $this->assertTrue($this->psDeleted);
        $this->assertSame('deleted', $this->brain['p1']['status']);
        $this->assertSame('deleted', $this->brain['p2']['status']);
        $post->refresh();
        $this->assertSame('ready', $post->status);
        $this->assertSame('idle', $post->publish_state);
        $this->assertNull($post->publish_progress);
        $this->assertSame([], $post->postsyncer['groups']);
        $this->assertSame('42', $post->postsyncer['cleared_groups'][0]['post_id']);
        $this->assertSame('DELETED', $post->postsyncer['cleared_groups'][0]['status']);
    }

    public function test_take_down_reports_a_published_postsyncer_half_and_keeps_the_post_until_it_is_gone(): void
    {
        $post = $this->splitPost();
        $this->psStatus = 'PUBLISHED';

        $this->withToken($this->token)->postJson("/api/v1/posts/{$post->human_id}/take-down")
            ->assertUnprocessable()
            ->assertJsonPath('problems.0', fn (string $m): bool => str_contains($m, 'PostSyncer facebook: PUBLISHED'));

        // The SocialReins half still went down and is recorded; the post is not reset.
        $this->assertFalse($this->psDeleted);
        $this->assertSame('deleted', $this->brain['p1']['status']);
        $post->refresh();
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('SCHEDULED', $post->postsyncer['groups'][0]['status']);

        // The owner removed it in PostSyncer: the retry finds it gone and resets the post.
        $this->psDeleted = true;
        $this->withToken($this->token)->postJson("/api/v1/posts/{$post->human_id}/take-down")->assertOk();
        $this->assertSame('ready', $post->refresh()->status);
    }
}
