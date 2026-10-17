<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VideosRescheduleApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Workspace $workspace;

    /** @var array<string, array<string, mixed>> PostSyncer posts keyed by id, the fake remote state */
    private array $remote = [];

    /** @var array<string, int> PUT calls per post id */
    private array $puts = [];

    /** @var array<string, string> Post id to failure mode for its next PUT: "reject" (4xx) or "lost" (applied, 503 returned) */
    private array $failNextPut = [];

    private CarbonImmutable $source;

    private CarbonImmutable $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->token = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('test client'),
        )['plaintext'];

        PostsyncerConfig::write($this->workspace, [
            'api_key' => 'test-api-key',
            'publish_enabled' => true,
            'video_publish_enabled' => true,
            'languages' => [
                'english' => ['workspace_id' => '853', 'platforms' => []],
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
            ],
        ]);

        $now = CarbonImmutable::now('Asia/Dhaka')->setSecond(0);
        $this->source = $now->addDays(2)->setTime(9, 0);
        $this->target = $now->addDays(3)->setTime(18, 30);

        $this->fakePostsyncer();
    }

    private function acting(): self
    {
        return $this->withToken($this->token);
    }

    public function test_reschedule_moves_every_group_and_checkpoints_the_new_time(): void
    {
        $video = $this->scheduledVideo(['42', '43']);

        $this->acting()->postJson('/api/v1/videos/'.$video->human_id.'/reschedule', [
            'when' => $this->target->toIso8601String(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.publish_state', 'succeeded')
            ->assertJsonPath('data.postsyncer.groups.0.scheduled_at', $this->target->format('Y-m-d H:i'))
            ->assertJsonPath('data.postsyncer.groups.1.scheduled_at', $this->target->format('Y-m-d H:i'));

        $video->refresh();
        $this->assertArrayNotHasKey('reschedule', $video->publish_progress);
        $this->assertSame('18:30', $video->publish_progress['completed_groups'][0]['expected_payload']['schedule_for']['time']);
        $this->assertSame(1, $this->puts['42']);
        $this->assertSame(1, $this->puts['43']);
    }

    public function test_reschedule_refuses_videos_that_are_not_scheduled(): void
    {
        $video = $this->scheduledVideo(['42']);
        $video->update(['status' => 'recorded']);

        $this->acting()->postJson('/api/v1/videos/'.$video->human_id.'/reschedule', [
            'when' => $this->target->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('when');

        $this->assertSame(0, $this->puts['42'] ?? 0);
    }

    public function test_reschedule_refuses_past_times_and_publishes_in_progress(): void
    {
        $video = $this->scheduledVideo(['42']);

        $this->acting()->postJson('/api/v1/videos/'.$video->human_id.'/reschedule', [
            'when' => CarbonImmutable::now()->subHour()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('when');

        $video->update(['publish_state' => 'running']);

        $this->acting()->postJson('/api/v1/videos/'.$video->human_id.'/reschedule', [
            'when' => $this->target->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('when');

        $this->assertSame(0, $this->puts['42'] ?? 0);
    }

    public function test_retry_after_partial_failure_only_moves_the_groups_still_at_the_old_time(): void
    {
        $video = $this->scheduledVideo(['42', '43']);
        $this->failNextPut['43'] = 'reject';

        $this->acting()->postJson('/api/v1/videos/'.$video->human_id.'/reschedule', [
            'when' => $this->target->toIso8601String(),
        ])->assertUnprocessable();

        $video->refresh();
        $this->assertSame('failed', $video->publish_progress['reschedule']['state']);
        $this->assertSame($this->target->format('Y-m-d H:i'), $video->postsyncer['groups'][0]['scheduled_at']);
        $this->assertSame($this->source->format('Y-m-d H:i'), $video->postsyncer['groups'][1]['scheduled_at']);

        // A different target is refused while the first attempt is unfinished.
        $this->acting()->postJson('/api/v1/videos/'.$video->human_id.'/reschedule', [
            'when' => $this->target->addHour()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('when');

        $this->acting()->postJson('/api/v1/videos/'.$video->human_id.'/reschedule', [
            'when' => $this->target->toIso8601String(),
        ])->assertOk();

        $this->assertSame(1, $this->puts['42']);
        $this->assertSame(2, $this->puts['43']);
        $video->refresh();
        $this->assertArrayNotHasKey('reschedule', $video->publish_progress);
    }

    public function test_a_lost_put_response_is_resolved_by_verifying_the_remote_time(): void
    {
        $video = $this->scheduledVideo(['42']);
        $this->failNextPut['42'] = 'lost';

        $this->acting()->postJson('/api/v1/videos/'.$video->human_id.'/reschedule', [
            'when' => $this->target->toIso8601String(),
        ])->assertOk();

        $video->refresh();
        $this->assertSame($this->target->format('Y-m-d H:i'), $video->postsyncer['groups'][0]['scheduled_at']);
        $this->assertArrayNotHasKey('reschedule', $video->publish_progress);
        $this->assertSame(1, $this->puts['42']);
    }

    public function test_reconcile_groups_deleted_resets_the_video_when_every_post_is_gone(): void
    {
        $video = $this->scheduledVideo(['42', '43']);
        unset($this->remote['42'], $this->remote['43']);

        $this->acting()->postJson('/api/v1/videos/'.$video->human_id.'/reconcile-groups-deleted', [
            'confirmed_deleted' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'recorded')
            ->assertJsonPath('data.publish_state', 'idle')
            ->assertJsonPath('data.postsyncer.groups', []);

        $video->refresh();
        $this->assertNull($video->publish_progress);
    }

    public function test_reconcile_groups_deleted_refuses_when_any_post_still_exists(): void
    {
        $video = $this->scheduledVideo(['42', '43']);
        unset($this->remote['43']);

        $this->acting()->postJson('/api/v1/videos/'.$video->human_id.'/reconcile-groups-deleted', [
            'confirmed_deleted' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('confirmed_deleted');

        $video->refresh();
        $this->assertSame('scheduled', $video->status);
        $this->assertCount(2, $video->postsyncer['groups']);
    }

    public function test_reconcile_groups_deleted_requires_confirmation(): void
    {
        $video = $this->scheduledVideo(['42']);
        unset($this->remote['42']);

        $this->acting()->postJson('/api/v1/videos/'.$video->human_id.'/reconcile-groups-deleted', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmed_deleted');
    }

    /**
     * @param  list<string>  $postIds
     */
    private function scheduledVideo(array $postIds): Video
    {
        $groups = [];
        $completed = [];

        foreach ($postIds as $index => $postId) {
            $payload = $this->payload($this->source, $postId);
            $this->remote[$postId] = $payload;

            $groups[] = [
                'post_id' => $postId,
                'status' => 'SCHEDULED',
                'scheduled_at' => $this->source->format('Y-m-d H:i'),
                'platforms' => ['facebook'],
                'language' => 'bangla',
            ];
            $completed[] = [
                'index' => $index,
                'group_key' => 'group-'.$postId,
                'post_id' => $postId,
                'status' => 'SCHEDULED',
                'scheduled_at' => $this->source->format('Y-m-d H:i'),
                'platforms' => ['facebook'],
                'language' => 'bangla',
                'expected_payload' => $payload,
            ];
        }

        return Video::factory()->for($this->workspace)->create([
            'human_id' => 'BV-RESCHEDULE',
            'number' => 301,
            'status' => 'scheduled',
            'language' => 'bn',
            'publish_state' => 'succeeded',
            'postsyncer' => ['groups' => $groups],
            'publish_progress' => [
                'version' => 1,
                'operation_id' => 'publish-operation',
                'state' => 'succeeded',
                'options' => [],
                'planned_groups' => [],
                'completed_groups' => $completed,
                'current' => null,
            ],
        ]);
    }

    /**
     * The fake PostSyncer keeps each post's last written payload. GET reflects
     * it, and PUT replaces its schedule.
     */
    private function fakePostsyncer(): void
    {
        Http::fake(function ($request) {
            if (! preg_match('#/posts/(\d+)$#', $request->url(), $matches)
                || ! isset($this->remote[$matches[1]])) {
                return Http::response(['message' => 'Not found'], 404);
            }

            $id = $matches[1];

            if ($request->method() === 'PUT') {
                $this->puts[$id] = ($this->puts[$id] ?? 0) + 1;
                $mode = $this->failNextPut[$id] ?? null;
                unset($this->failNextPut[$id]);

                if ($mode === 'reject') {
                    return Http::response(['message' => 'bad request'], 422);
                }

                $this->remote[$id]['schedule_for'] = $request->data()['schedule_for'];

                return $mode === 'lost'
                    ? Http::response(['message' => 'gateway timeout'], 503)
                    : Http::response($this->remoteBody($id), 200);
            }

            return Http::response($this->remoteBody($id), 200);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteBody(string $id): array
    {
        $payload = $this->remote[$id];
        $schedule = $payload['schedule_for'];

        return [
            'id' => (int) $id,
            'workspace_id' => 15211,
            'content' => [['text' => 'Hello', 'media' => [['id' => 915]]]],
            'platforms' => [[
                'platform' => 'facebook',
                'account_id' => 100,
                'settings' => ['post_type' => 'REEL'],
            ]],
            'status' => 'SCHEDULED',
            'scheduled_at' => $schedule['date'].' '.$schedule['time'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CarbonImmutable $when, string $postId): array
    {
        return [
            'workspace_id' => 15211,
            'content' => [['text' => 'Hello', 'media' => [915]]],
            'accounts' => [[
                'id' => 100,
                'settings' => ['post_type' => 'REEL'],
            ]],
            'schedule_type' => 'schedule',
            'schedule_for' => [
                'date' => $when->format('Y-m-d'),
                'time' => $when->format('H:i'),
                'timezone' => 'Asia/Dhaka',
            ],
        ];
    }
}
