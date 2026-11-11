<?php

namespace Tests\Feature\Socialreins;

use App\Actions\Postsyncer\EnqueueVideoPublishAction;
use App\Actions\Postsyncer\PublishVideoAction;
use App\Actions\Postsyncer\SyncScheduledPostsAction;
use App\Actions\Socialreins\PublishToSocialreinsAction;
use App\Jobs\PublishSocialreinsJob;
use App\Jobs\PublishVideoJob;
use App\Models\SocialreinsGroup;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakesPublicDns;
use Tests\TestCase;

/** One video published through both drivers: PostSyncer (TikTok) first, then SocialReins for the platforms it covers. */
class SocialreinsSplitVideoPublishTest extends TestCase
{
    use FakesPublicDns, RefreshDatabase;

    private const SR_URL = 'https://brain.test/v1/post-groups';

    private const PS_URL = 'https://postsyncer.com/api/v1/posts';

    private const SET = '11111111-1111-4111-8111-111111111111';

    private const COVERED = ['facebook', 'instagram', 'linkedin', 'youtube'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolveHostsTo();
        Queue::fake();
    }

    /** @param  list<string>  $covered  SocialReins platforms for english */
    private function workspace(array $covered = self::COVERED, bool $tiktokAccount = true): Workspace
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'api_key' => 'k',
            'publish_enabled' => true,
            'video_publish_enabled' => true,
            'default_language' => 'english',
            'languages' => ['english' => [
                'workspace_id' => '853',
                'platforms' => [
                    'facebook' => ['account_id' => 100, 'handle' => '@fb'],
                    ...($tiktokAccount ? ['tiktok' => ['account_id' => 103, 'handle' => '@tt']] : []),
                ],
            ]],
            'post_types' => ['platforms' => [
                'facebook' => ['reel' => 'on'],
                'tiktok' => ['reel' => 'on'],
            ]],
        ]);
        SocialreinsConfig::write($workspace, [
            'publish_driver' => 'socialreins',
            'base_url' => 'https://brain.test/v1',
            'tiktok_privacy_level' => 'SELF_ONLY',
            'default_language' => 'english',
            'languages' => ['english' => ['set_id' => self::SET, 'token' => 'sr_set_live_english', 'platforms' => $covered]],
        ]);

        return $workspace->fresh();
    }

    private function makeVideo(Workspace $workspace): Video
    {
        return Video::factory()->for($workspace)->create([
            'language' => 'en',
            'captions' => ['facebook' => ['caption' => 'EN fb'], 'tiktok' => ['caption' => 'EN tt']],
            'video_drive_url' => 'https://drive.google.com/file/d/vid/view',
            'status' => 'recorded',
        ]);
    }

    /** @param  list<mixed>  $srAnswers  brain answers for successive `POST /post-groups` */
    private function fakeApis(array $srAnswers = []): void
    {
        $sr = Http::sequence();
        foreach ($srAnswers ?: [[$this->srGroup(), 201]] as [$body, $status]) {
            $sr->push($body, $status);
        }

        $this->fakeHttp([
            'drive.usercontent.google.com/*' => Http::response('fake-media-bytes'),
            'upload.postsyncer.com/api/v1/media/upload/file' => Http::response(['media' => ['id' => 915]], 201),
            self::SR_URL => $sr,
            self::PS_URL => Http::response(['id' => 42, 'status' => 'published'], 201),
            self::PS_URL.'/42' => Http::response([
                'id' => 42,
                'workspace_id' => 853,
                'content' => [['text' => 'EN tt', 'media' => [['id' => 915]]]],
                'platforms' => [['platform' => 'tiktok', 'account_id' => 103, 'settings' => ['description' => 'EN tt']]],
                'status' => 'PUBLISHED',
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function srGroup(): array
    {
        return [
            'group_id' => 'aaaaaaaa-0000-4000-8000-000000000001',
            'expected_targets' => 1,
            'posts' => [[
                'id' => 'bbbbbbbb-0000-4000-8000-000000000001',
                'platform' => 'facebook',
                'status' => 'scheduled',
                'error' => null,
                'targets' => [],
            ]],
        ];
    }

    /** Run every queued publish job once, as the worker would, oldest first. */
    private function runQueued(): void
    {
        $ran = [];
        do {
            $jobs = collect(Queue::pushedJobs())->flatten(1)->pluck('job')
                ->reject(fn (object $job): bool => in_array(spl_object_id($job), $ran, true));
            foreach ($jobs as $job) {
                $ran[] = spl_object_id($job);
                match (true) {
                    $job instanceof PublishVideoJob => $job->handle(app(PublishVideoAction::class)),
                    $job instanceof PublishSocialreinsJob => $job->handle(app(PublishToSocialreinsAction::class)),
                    default => null,
                };
            }
        } while ($jobs->isNotEmpty());
    }

    /** @return list<Request> */
    private function sentTo(string $url): array
    {
        return Http::recorded()->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $r): bool => $r->method() === 'POST' && $r->url() === $url)->values()->all();
    }

    private function publish(Video $video, array $options = []): Video
    {
        return app(EnqueueVideoPublishAction::class)->handle($video, $video->workspace->fresh(), $options);
    }

    public function test_a_split_video_creates_postsyncer_groups_and_socialreins_rows_only_for_their_own_platforms(): void
    {
        $this->fakeApis();
        $video = $this->makeVideo($this->workspace());

        $queued = $this->publish($video);

        Queue::assertPushed(PublishVideoJob::class, 1);
        Queue::assertNotPushed(PublishSocialreinsJob::class);
        $this->assertSame(['tiktok'], $queued->publish_progress['options']['platforms']);
        $this->assertSame(['facebook'], $queued->publish_progress['split']['socialreins']['platforms']);

        $this->runQueued();

        [$psCreate] = $this->sentTo(self::PS_URL);
        $this->assertSame([103], array_column($psCreate['accounts'], 'id'));
        $this->assertStringNotContainsString('EN fb', $psCreate->body());
        $this->assertCount(1, $this->sentTo(self::PS_URL));

        [$srCreate] = $this->sentTo(self::SR_URL);
        $this->assertSame(['facebook'], $srCreate['platforms']);
        $this->assertSame(['https://drive.usercontent.google.com/download?id=vid&export=download&confirm=t'], $srCreate['overrides']['facebook']['media']);
        $this->assertCount(1, $this->sentTo(self::SR_URL));

        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertSame('socialreins', $video->publish_progress['driver']);
        $this->assertSame('succeeded', $video->publish_progress['state']);
        $this->assertSame(['tiktok'], $video->postsyncer['groups'][0]['platforms']);
        $this->assertSame('42', (string) $video->publish_progress['split']['completed_groups'][0]['post_id']);
        $this->assertSame(1, SocialreinsGroup::query()->where('subject_type', 'video')->where('state', 'created')->count());
        $this->assertTrue(SocialreinsConfig::routesToSocialreins($video, $video->workspace));

        // A redelivered PostSyncer job after the hand-over does nothing.
        app(PublishVideoAction::class)->handle($video, ['platforms' => ['tiktok']]);
        $this->assertCount(1, $this->sentTo(self::PS_URL));
        $this->assertSame('succeeded', $video->fresh()->publish_state);
    }

    public function test_a_socialreins_failure_after_postsyncer_retries_only_socialreins(): void
    {
        $this->fakeApis([[['error' => 'too_long'], 422], [$this->srGroup(), 201]]);
        $video = $this->makeVideo($this->workspace());

        $this->publish($video);
        $this->runQueued();

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertSame('failed', $video->publish_progress['state']);
        $this->assertNotEmpty($video->postsyncer['groups']);
        $this->assertTrue($video->canRetryPublish());

        // The retry may send any platforms: only the SocialReins half goes out.
        $this->publish($video, ['platforms' => ['facebook', 'tiktok']]);
        $this->runQueued();

        $this->assertCount(1, $this->sentTo(self::PS_URL));
        $srCreates = $this->sentTo(self::SR_URL);
        $this->assertCount(2, $srCreates);
        $this->assertSame(['facebook'], $srCreates[1]['platforms']);
        $video->refresh();
        $this->assertSame('succeeded', $video->publish_state);
        $this->assertSame(['tiktok'], $video->publish_progress['split']['postsyncer']);
    }

    public function test_a_postsyncer_failure_retries_postsyncer_then_hands_over_once(): void
    {
        $this->fakeApis();
        $workspace = $this->workspace(tiktokAccount: false);
        $video = $this->makeVideo($workspace);

        $this->publish($video);
        $this->runQueued();

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertArrayNotHasKey('driver', $video->publish_progress);
        $this->assertSame([], $this->sentTo(self::PS_URL));
        $this->assertSame([], $this->sentTo(self::SR_URL));

        PostsyncerConfig::write($workspace, ['languages' => ['english' => [
            'workspace_id' => '853',
            'platforms' => [
                'facebook' => ['account_id' => 100, 'handle' => '@fb'],
                'tiktok' => ['account_id' => 103, 'handle' => '@tt'],
            ],
        ]]]);

        $this->publish($video->fresh());
        $this->runQueued();

        $this->assertCount(1, $this->sentTo(self::PS_URL));
        $this->assertCount(1, $this->sentTo(self::SR_URL));
        $this->assertSame('succeeded', $video->fresh()->publish_state);
    }

    public function test_a_fully_covered_video_goes_whole_to_socialreins(): void
    {
        $queued = $this->publish($this->makeVideo($this->workspace([...self::COVERED, 'tiktok'])));

        Queue::assertPushed(PublishSocialreinsJob::class, fn (PublishSocialreinsJob $job): bool => ! isset($job->options['platforms']));
        Queue::assertNotPushed(PublishVideoJob::class);
        $this->assertArrayNotHasKey('split', $queued->publish_progress);
    }

    public function test_an_uncovered_video_goes_whole_to_postsyncer(): void
    {
        $queued = $this->publish($this->makeVideo($this->workspace(['twitter'])));

        Queue::assertPushed(PublishVideoJob::class, fn (PublishVideoJob $job): bool => ! isset($job->options['platforms']));
        Queue::assertNotPushed(PublishSocialreinsJob::class);
        $this->assertArrayNotHasKey('split', $queued->publish_progress);
    }

    public function test_the_postsyncer_sync_does_not_mark_a_split_video_posted_while_its_socialreins_half_failed(): void
    {
        $this->fakeApis([[['error' => 'too_long'], 422]]);
        $video = $this->makeVideo($this->workspace());

        $this->publish($video);
        $this->runQueued();
        $video->refresh()->forceFill(['status' => 'scheduled'])->save();
        $this->assertSame('failed', $video->publish_state);

        app(SyncScheduledPostsAction::class)->handle();

        $video->refresh();
        $this->assertSame('PUBLISHED', $video->postsyncer['groups'][0]['status']);
        $this->assertSame('scheduled', $video->status);
    }
}
