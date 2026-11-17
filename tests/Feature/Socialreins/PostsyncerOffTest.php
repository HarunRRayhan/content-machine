<?php

namespace Tests\Feature\Socialreins;

use App\Actions\Postsyncer\EnqueuePostPublishAction;
use App\Actions\Postsyncer\EnqueueVideoPublishAction;
use App\Actions\Postsyncer\ReconcileVideoGroupsDeletedAction;
use App\Actions\Socialreins\MoveOffPostsyncerAction;
use App\Actions\Socialreins\PublishToSocialreinsAction;
use App\Actions\Socialreins\RescheduleSplitPostAction;
use App\Data\Postsyncer\ReschedulePostData;
use App\Jobs\PublishPostJob;
use App\Jobs\PublishSocialreinsJob;
use App\Jobs\PublishVideoJob;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Socialreins\SocialreinsConfig;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakesPublicDns;
use Tests\TestCase;

/** PostSyncer switched off: nothing new reaches it, and socialreins:move-off-postsyncer moves what is scheduled there. */
class PostsyncerOffTest extends TestCase
{
    use FakesPublicDns, RefreshDatabase;

    private const SET = '11111111-1111-4111-8111-111111111111';

    private const SR_URL = 'https://brain.test/v1/post-groups';

    private const PS_POST = 'https://postsyncer.com/api/v1/posts/42';

    private Workspace $workspace;

    /** Fake PostSyncer posts by id: their status, the GET answer code, and whether they were deleted. */
    private string $psStatus = 'SCHEDULED';

    /** @var array<string, int> */
    private array $psGetStatus = [];

    /** @var array<string, bool> */
    private array $psDeleted = [];

    private int $psWorkspacesStatus = 200;

    /** @var list<array{0: array<string, mixed>, 1: int}> successive brain answers to POST /post-groups */
    private array $brainAnswers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolveHostsTo();
        Queue::fake();

        $this->workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);
        PostsyncerConfig::write($this->workspace, [
            'api_key' => 'k',
            'publish_enabled' => true,
            'video_publish_enabled' => true,
            'default_language' => 'english',
            'languages' => ['english' => ['workspace_id' => '853', 'platforms' => [
                'facebook' => ['account_id' => 100, 'handle' => '@fb'],
                'linkedin' => ['account_id' => 102, 'handle' => '@li'],
                'tiktok' => ['account_id' => 103, 'handle' => '@tt'],
            ]]],
            'post_types' => ['platforms' => [
                'facebook' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
                'linkedin' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
                'tiktok' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
            ]],
        ]);
        SocialreinsConfig::write($this->workspace, [
            'publish_driver' => 'postsyncer',
            'postsyncer_enabled' => false,
            'base_url' => 'https://brain.test/v1',
            'tiktok_privacy_level' => 'SELF_ONLY',
            'default_language' => 'english',
            'languages' => ['english' => ['set_id' => self::SET, 'token' => 'sr_set_live_english', 'platforms' => ['facebook', 'linkedin']]],
        ]);
        $this->workspace->refresh();

        Http::fake(fn (Request $request) => $this->answer($request));
        Http::preventStrayRequests();
    }

    private function answer(Request $request): mixed
    {
        if ($request->url() === 'https://postsyncer.com/api/v1/workspaces') {
            return Http::response(['data' => []], $this->psWorkspacesStatus);
        }

        if (str_starts_with($request->url(), 'https://postsyncer.com/api/v1/posts/')) {
            $id = basename($request->url());
            if ($this->psDeleted[$id] ?? false) {
                return Http::response(['message' => 'Not found'], 404);
            }
            if ($request->method() === 'DELETE') {
                $this->psDeleted[$id] = true;

                return Http::response(['message' => 'Post deleted successfully']);
            }

            return Http::response(['id' => (int) $id, 'status' => $this->psStatus], $this->psGetStatus[$id] ?? 200);
        }

        if ($request->method() === 'POST' && $request->url() === self::SR_URL) {
            [$body, $status] = array_shift($this->brainAnswers) ?? [$this->brainGroup(['facebook']), 201];

            return Http::response($body, $status);
        }

        return Http::response(['error' => 'not_stubbed'], 404);
    }

    /**
     * @param  list<string>  $platforms
     * @return array<string, mixed>
     */
    private function brainGroup(array $platforms, string $suffix = '1'): array
    {
        return [
            'group_id' => "aaaaaaaa-0000-4000-8000-00000000000{$suffix}",
            'expected_targets' => count($platforms),
            'posts' => array_map(fn (string $platform): array => [
                'id' => "bbbbbbbb-0000-4000-8000-0000000{$suffix}000{$platform}",
                'platform' => $platform,
                'status' => 'scheduled',
                'error' => null,
                'targets' => [],
            ], $platforms),
        ];
    }

    private function actingAsOwner(): void
    {
        $team = $this->workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);
        $this->actingAs($user);
    }

    private function runQueued(): void
    {
        foreach (collect(Queue::pushedJobs())->flatten(1)->pluck('job') as $job) {
            if ($job instanceof PublishSocialreinsJob) {
                $job->handle(app(PublishToSocialreinsAction::class));
            }
        }
    }

    /** @return list<Request> */
    private function brainCreates(): array
    {
        return Http::recorded()->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $r): bool => $r->method() === 'POST' && $r->url() === self::SR_URL)->values()->all();
    }

    private function assertPostsyncerNotCalled(): void
    {
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'postsyncer.com'));
    }

    private function makePost(array $platforms = ['facebook', 'tiktok'], array $attributes = []): Post
    {
        return Post::factory()->for($this->workspace)->create([
            'language' => 'en',
            'platforms' => $platforms,
            'captions' => ['English' => array_combine($platforms, array_map(fn (string $p): array => ['caption' => "EN {$p}"], $platforms))],
            'image_drive_urls' => [],
            'status' => 'ready',
            ...$attributes,
        ]);
    }

    private function makeVideo(array $attributes = []): Video
    {
        return Video::factory()->for($this->workspace)->create([
            'language' => 'en',
            'captions' => ['facebook' => ['caption' => 'EN fb'], 'tiktok' => ['caption' => 'EN tt']],
            'video_drive_url' => 'https://drive.google.com/file/d/vid/view',
            'status' => 'recorded',
            ...$attributes,
        ]);
    }

    /**
     * As a PostSyncer publish leaves an item: group 42 scheduled at $at.
     *
     * @param  list<string>  $platforms
     * @return array<string, mixed>
     */
    private function postsyncerOnly(array $platforms, CarbonImmutable $at, array $more = []): array
    {
        $group = fn (string $id, array $platforms): array => [
            'post_id' => $id, 'status' => 'SCHEDULED', 'scheduled_at' => $at->timezone('Asia/Dhaka')->format('Y-m-d H:i'),
            'platforms' => $platforms, 'language' => 'english',
        ];

        return [
            'status' => 'scheduled',
            'publish_state' => 'succeeded',
            'postsyncer' => ['groups' => [$group('42', $platforms), ...array_map(fn (string $id) => $group($id, $more[$id]), array_keys($more))]],
            'publish_progress' => ['version' => 1, 'state' => 'succeeded', 'options' => ['when' => $at->toIso8601String()], 'completed_groups' => []],
        ];
    }

    private function at(): CarbonImmutable
    {
        return CarbonImmutable::now()->addDays(2)->setSecond(0)->setMicrosecond(0);
    }

    public function test_a_post_with_tiktok_goes_to_socialreins_without_tiktok_and_never_calls_postsyncer(): void
    {
        $post = $this->makePost();

        $queued = app(EnqueuePostPublishAction::class)->handle($post, $this->workspace, ['when' => $this->at()->toIso8601String()]);

        Queue::assertNotPushed(PublishPostJob::class);
        Queue::assertPushed(PublishSocialreinsJob::class, 1);
        $this->assertSame(['english:tiktok'], $queued->publish_progress['left_out']);

        $this->runQueued();

        [$create] = $this->brainCreates();
        $this->assertSame(['facebook'], $create['platforms']);
        $this->assertSame('scheduled', $post->fresh()->status);
        $this->assertSame(['english:tiktok'], $post->fresh()->publish_progress['left_out']);
        $this->assertPostsyncerNotCalled();
    }

    public function test_a_video_with_tiktok_goes_to_socialreins_without_tiktok_and_never_calls_postsyncer(): void
    {
        $video = $this->makeVideo();

        $queued = app(EnqueueVideoPublishAction::class)->handle($video, $this->workspace, ['when' => $this->at()->toIso8601String()]);

        Queue::assertNotPushed(PublishVideoJob::class);
        $this->assertSame(['english:tiktok'], $queued->publish_progress['left_out']);

        $this->runQueued();

        [$create] = $this->brainCreates();
        $this->assertSame(['facebook'], $create['platforms']);
        $this->assertSame('scheduled', $video->fresh()->status);
        $this->assertPostsyncerNotCalled();

        $this->actingAsOwner();
        $this->get(route('videos.show', $video))
            ->assertInertia(fn ($page) => $page->where('video.publish_left_out', ['english:tiktok']));
    }

    public function test_an_item_with_nothing_socialreins_covers_is_refused_with_a_clear_message(): void
    {
        $post = $this->makePost(['tiktok']);

        try {
            app(EnqueuePostPublishAction::class)->handle($post, $this->workspace);
            $this->fail('Expected a refusal.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Nothing to publish: SocialReins does not cover english:tiktok, and PostSyncer is off.', $exception->getMessage());
        }

        Queue::assertNothingPushed();
        $this->assertPostsyncerNotCalled();
    }

    public function test_an_item_still_on_postsyncer_is_not_published_there(): void
    {
        $post = $this->makePost(attributes: [...$this->postsyncerOnly(['facebook'], $this->at()), 'publish_state' => 'failed']);

        $this->expectExceptionMessage('socialreins:move-off-postsyncer');

        try {
            app(EnqueuePostPublishAction::class)->handle($post, $this->workspace);
        } finally {
            Queue::assertNothingPushed();
            $this->assertPostsyncerNotCalled();
        }
    }

    public function test_the_postsyncer_background_commands_do_nothing_when_it_is_off(): void
    {
        $this->makePost(attributes: $this->postsyncerOnly(['facebook'], CarbonImmutable::now()->subMinutes(5)));
        $this->makePost(attributes: ['publish_state' => 'queued', 'publish_progress' => ['version' => 1, 'state' => 'queued', 'options' => []]]);

        $this->artisan('postsyncer:sync-scheduled')->assertSuccessful();
        $this->artisan('postsyncer:dispatch-pending-publishes')->assertSuccessful()
            ->expectsOutput('Dispatched 0 pending PostSyncer publish(es).');

        Queue::assertNotPushed(PublishPostJob::class);
        $this->assertPostsyncerNotCalled();
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $at = $this->at();
        $video = $this->makeVideo($this->postsyncerOnly(['facebook', 'tiktok'], $at));
        $before = $video->fresh()->getAttributes();

        $this->artisan('socialreins:move-off-postsyncer')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain("{$video->human_id}  {$at->utc()->toIso8601String()}  postsyncer: english:facebook, english:tiktok  to socialreins: english:facebook  left out: english:tiktok  => would move")
            ->assertSuccessful();

        $this->assertEquals($before, $video->fresh()->getAttributes());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_apply_moves_a_video_that_was_wholly_on_postsyncer(): void
    {
        $at = $this->at();
        $video = $this->makeVideo($this->postsyncerOnly(['facebook', 'tiktok'], $at));

        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true])
            ->expectsOutputToContain('=> moved: handed to SocialReins')
            ->assertSuccessful();

        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && $r->url() === self::PS_POST);
        $this->runQueued();

        [$create] = $this->brainCreates();
        $this->assertSame(['facebook'], $create['platforms']);
        $this->assertSame($at->utc()->format('Y-m-d\TH:i:s\Z'), $create['scheduled_at']);

        $video->refresh();
        $this->assertSame([], $video->postsyncer['groups']);
        $this->assertSame('42', $video->postsyncer['cleared_groups'][0]['post_id']);
        $this->assertSame('DELETED', $video->postsyncer['cleared_groups'][0]['status']);
        $this->assertSame(['english:tiktok'], $video->publish_progress['left_out']);
        $this->assertSame('scheduled', $video->status);
        $this->assertSame('succeeded', $video->publish_state);

        // Done: a second run finds nothing to move and sends nothing.
        $sent = Http::recorded()->count();
        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true])->expectsOutputToContain('0 item(s)');
        $this->assertSame($sent, Http::recorded()->count());
    }

    public function test_apply_moves_the_postsyncer_half_of_a_split_post(): void
    {
        $at = $this->at();
        $post = $this->makePost(['facebook', 'linkedin', 'tiktok'], [
            ...$this->postsyncerOnly(['linkedin', 'tiktok'], $at),
            'publish_progress' => [
                'driver' => 'socialreins', 'state' => 'succeeded', 'options' => ['platforms' => ['facebook']],
                'split' => ['postsyncer' => ['linkedin', 'tiktok'], 'socialreins' => ['platforms' => ['facebook']], 'completed_groups' => []],
            ],
        ]);
        SocialreinsGroup::query()->create([
            'workspace_id' => $this->workspace->id, 'subject_type' => 'post', 'subject_id' => $post->id,
            'language' => 'english', 'sequence' => 1, 'idempotency_key' => 'k1', 'state' => 'created',
            'request' => json_encode(['scheduled_at' => $at->utc()->toIso8601String()]),
            'group_id' => 'aaaaaaaa-0000-4000-8000-000000000009', 'expected_targets' => 1,
            'results' => ['p1' => ['platform' => 'facebook', 'status' => 'scheduled', 'error' => null, 'at' => 0, 'targets' => []]],
        ]);
        $this->brainAnswers = [[$this->brainGroup(['linkedin'], '2'), 201]];

        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true])
            ->expectsOutputToContain('to socialreins: english:linkedin  left out: english:tiktok  => moved: handed to SocialReins');
        $this->runQueued();

        [$create] = $this->brainCreates();
        $this->assertSame(['linkedin'], $create['platforms']);
        $this->assertSame($at->utc()->format('Y-m-d\TH:i:s\Z'), $create['scheduled_at']);
        $this->assertSame(2, SocialreinsGroup::query()->where('subject_id', $post->id)->max('sequence'));

        $post->refresh();
        $this->assertArrayNotHasKey('split', $post->publish_progress);
        $this->assertSame(['english:tiktok'], $post->publish_progress['left_out']);
        $this->assertSame([], $post->postsyncer['groups']);
        $this->assertSame('scheduled', $post->status);
    }

    public function test_a_failed_postsyncer_delete_leaves_the_item_untouched(): void
    {
        $this->psGetStatus['42'] = 500;
        $video = $this->makeVideo($this->postsyncerOnly(['facebook', 'tiktok'], $this->at()));
        $before = $video->fresh()->getAttributes();

        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true])
            ->expectsOutputToContain('Nothing was changed.');

        $this->assertEquals($before, $video->fresh()->getAttributes());
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'DELETE' || str_starts_with($r->url(), 'https://brain.test/'));
        Queue::assertNothingPushed();
    }

    public function test_a_published_postsyncer_post_is_not_moved(): void
    {
        $this->psStatus = 'PUBLISHED';
        $video = $this->makeVideo($this->postsyncerOnly(['facebook'], $this->at()));
        $before = $video->fresh()->getAttributes();

        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true])
            ->expectsOutputToContain('PUBLISHED in PostSyncer');

        $this->assertEquals($before, $video->fresh()->getAttributes());
        Queue::assertNothingPushed();
    }

    public function test_an_old_record_that_keeps_its_language_under_lang_is_read_and_skipped_when_published(): void
    {
        $state = $this->postsyncerOnly(['facebook'], $this->at());
        $state['postsyncer']['groups'][0]['status'] = 'PUBLISHED';
        $state['postsyncer']['groups'][0]['lang'] = 'english';
        unset($state['postsyncer']['groups'][0]['language']);
        $video = $this->makeVideo($state);
        $before = $video->fresh()->getAttributes();

        $this->artisan('socialreins:move-off-postsyncer')
            ->expectsOutputToContain('english:facebook')
            ->expectsOutputToContain('already published');

        $this->assertEquals($before, $video->fresh()->getAttributes());
    }

    public function test_an_item_due_in_ten_minutes_is_skipped(): void
    {
        $video = $this->makeVideo($this->postsyncerOnly(['facebook'], CarbonImmutable::now()->addMinutes(10)));
        $before = $video->fresh()->getAttributes();

        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true])
            ->expectsOutputToContain('skipped: due within 15 minutes');

        $this->assertEquals($before, $video->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_a_failed_hand_over_is_recorded_and_sent_again_on_the_next_run(): void
    {
        $video = $this->makeVideo($this->postsyncerOnly(['facebook', 'tiktok'], $this->at()));
        $this->brainAnswers = [[['error' => 'server_error'], 422], [$this->brainGroup(['facebook'], '2'), 201]];

        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true]);
        $this->runQueued();

        $video->refresh();
        $this->assertSame('failed', $video->publish_state);
        $this->assertSame('done', $video->publish_progress['move_off_postsyncer']['stage']);
        $this->assertSame([], $video->postsyncer['groups']);

        Queue::fake();
        $this->artisan('socialreins:move-off-postsyncer')
            ->expectsOutputToContain('=> would send the failed SocialReins hand-over again');
        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true])
            ->expectsOutputToContain('=> moved: handed to SocialReins');
        $this->runQueued();

        $this->assertCount(2, $this->brainCreates());
        $this->assertSame('succeeded', $video->fresh()->publish_state);
        $this->assertSame(1, SocialreinsGroup::query()->where('subject_id', $video->id)->where('state', 'created')->count());
        // GET and DELETE of post 42 on the first run only: PostSyncer is never asked again.
        $this->assertSame(2, Http::recorded()->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'postsyncer.com'))->count());
    }

    public function test_a_move_part_way_through_resumes_even_when_due_soon_and_the_page_says_so(): void
    {
        // As a run that died right after deleting post 42 leaves it.
        $at = CarbonImmutable::now()->addMinutes(5)->setSecond(0);
        $attributes = $this->postsyncerOnly(['facebook', 'tiktok'], $at);
        $attributes['postsyncer']['groups'][0]['status'] = 'DELETED';
        $attributes['publish_progress']['move_off_postsyncer'] = [
            'when' => $at->utc()->toIso8601String(), 'stage' => 'moving', 'handed' => [], 'note' => 'Being moved off PostSyncer: not finished yet.',
            'groups' => [['post_id' => '42', 'postsyncer' => ['english:facebook', 'english:tiktok'], 'move' => ['english:facebook'], 'left_out' => ['english:tiktok']]],
            'postsyncer' => ['english:facebook', 'english:tiktok'], 'move' => ['english:facebook'], 'left_out' => ['english:tiktok'],
        ];
        $video = $this->makeVideo($attributes);

        $this->actingAsOwner();
        $this->get(route('videos.show', $video))
            ->assertInertia(fn ($page) => $page->where('video.publish_moving', 'Being moved off PostSyncer: not finished yet.'));

        $this->artisan('socialreins:move-off-postsyncer')->expectsOutputToContain('=> would finish the interrupted move');
        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true])->expectsOutputToContain('=> moved: handed to SocialReins');
        $this->runQueued();

        [$create] = $this->brainCreates();
        $this->assertSame(['facebook'], $create['platforms']);
        $this->assertSame('done', $video->fresh()->publish_progress['move_off_postsyncer']['stage']);
        $this->assertPostsyncerNotCalled();
    }

    public function test_a_404_counts_as_gone_only_once_the_postsyncer_key_is_proven(): void
    {
        $this->psDeleted['42'] = true;
        $this->psWorkspacesStatus = 401;
        $video = $this->makeVideo($this->postsyncerOnly(['facebook'], $this->at()));
        $before = $video->fresh()->getAttributes();

        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true])
            ->expectsOutputToContain('BEFORE cancelling the PostSyncer subscription')
            ->expectsOutputToContain('its API key could not be confirmed');

        $this->assertEquals($before, $video->fresh()->getAttributes());
        Queue::assertNothingPushed();

        // With the key working, the same 404 means it is already gone.
        $this->psWorkspacesStatus = 200;
        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true])->expectsOutputToContain('=> moved: handed to SocialReins');
        Queue::assertPushed(PublishSocialreinsJob::class, 1);
    }

    public function test_a_partial_take_down_hands_over_what_was_deleted_and_names_the_rest(): void
    {
        $at = $this->at();
        $video = $this->makeVideo([
            ...$this->postsyncerOnly(['facebook'], $at, ['43' => ['linkedin']]),
            'captions' => ['facebook' => ['caption' => 'EN fb'], 'linkedin' => ['caption' => 'EN li']],
        ]);
        $this->psGetStatus['43'] = 500;

        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true])
            ->expectsOutputToContain('=> partly moved: Moving off PostSyncer stopped: PostSyncer linkedin');
        $this->runQueued();

        [$create] = $this->brainCreates();
        $this->assertSame(['facebook'], $create['platforms']);
        $video->refresh();
        $this->assertSame(['43'], array_column($video->postsyncer['groups'], 'post_id'));
        $this->assertSame('moving', $video->publish_progress['move_off_postsyncer']['stage']);
        $this->assertStringContainsString('Not moved yet: english:linkedin', $video->publish_progress['move_off_postsyncer']['note']);

        // PostSyncer answers again: the next run deletes 43 and hands linkedin over.
        Queue::fake();
        unset($this->psGetStatus['43']);
        $this->brainAnswers = [[$this->brainGroup(['linkedin'], '2'), 201]];
        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true])->expectsOutputToContain('=> moved: handed to SocialReins');
        $this->runQueued();

        $this->assertSame(['linkedin'], $this->brainCreates()[1]['platforms']);
        $this->assertSame([], $video->fresh()->postsyncer['groups']);
        $this->assertSame('done', $video->fresh()->publish_progress['move_off_postsyncer']['stage']);
    }

    public function test_a_second_run_on_the_same_item_does_nothing(): void
    {
        $video = $this->makeVideo($this->postsyncerOnly(['facebook'], $this->at()));
        $stale = $video->fresh();

        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true]);
        // A second run that read the item before the first one claimed it.
        $result = app(MoveOffPostsyncerAction::class)->handle($stale, true);

        $this->assertSame('skipped: another run is moving it', $result?->outcome);
        Queue::assertPushed(PublishSocialreinsJob::class, 1);
        Http::assertSentCount(2); // one GET and one DELETE of post 42
    }

    public function test_a_language_with_no_saved_platform_list_covers_nothing_when_postsyncer_is_off(): void
    {
        $settings = $this->workspace->settings;
        $settings['socialreins']['languages']['bangla'] = ['set_id' => self::SET];
        $this->workspace->forceFill(['settings' => $settings])->save();
        $post = $this->makePost(['facebook'], ['captions' => [
            'English' => ['facebook' => ['caption' => 'EN fb']],
            'Bangla' => ['facebook' => ['caption' => 'BN fb']],
        ]]);

        $this->assertSame(
            ['covered' => ['english:facebook'], 'left_out' => ['bangla:facebook']],
            SocialreinsConfig::coverage($post, $this->workspace->fresh()),
        );
    }

    public function test_with_postsyncer_on_a_named_platform_is_not_added_to_a_live_language(): void
    {
        $settings = $this->workspace->settings;
        $settings['postsyncer_enabled'] = true;
        $settings['publish_driver'] = 'socialreins';
        $this->workspace->forceFill(['settings' => $settings])->save();
        $post = $this->makePost(['facebook', 'linkedin'], [
            'publish_state' => 'queued',
            'publish_progress' => ['driver' => 'socialreins', 'state' => 'failed', 'options' => ['platforms' => ['facebook', 'linkedin']]],
        ]);
        SocialreinsGroup::query()->create([
            'workspace_id' => $this->workspace->id, 'subject_type' => 'post', 'subject_id' => $post->id,
            'language' => 'english', 'sequence' => 1, 'idempotency_key' => 'k1', 'state' => 'created',
            'request' => json_encode(['scheduled_at' => $this->at()->utc()->toIso8601String()]),
            'group_id' => 'aaaaaaaa-0000-4000-8000-000000000009', 'expected_targets' => 1,
            'results' => ['p1' => ['platform' => 'facebook', 'status' => 'scheduled', 'error' => null, 'at' => 0, 'targets' => []]],
        ]);

        app(PublishToSocialreinsAction::class)->handle($post, ['platforms' => ['facebook', 'linkedin']]);

        // As before this change: a live language only gets platforms brain left out.
        $this->assertSame([], $this->brainCreates());
    }

    public function test_reschedule_and_reconcile_refuse_an_item_still_on_postsyncer(): void
    {
        $post = $this->makePost(attributes: $this->postsyncerOnly(['facebook'], $this->at()));
        $video = $this->makeVideo($this->postsyncerOnly(['facebook'], $this->at()));
        $data = new ReschedulePostData($this->at()->addDay());

        foreach ([
            fn () => app(RescheduleSplitPostAction::class)->handle($post, $this->workspace, $data),
            fn () => app(RescheduleSplitPostAction::class)->handle($video, $this->workspace, $data),
            fn () => app(ReconcileVideoGroupsDeletedAction::class)->handle($video, $this->workspace),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected a refusal.');
            } catch (PostsyncerException $exception) {
                $this->assertStringContainsString('socialreins:move-off-postsyncer', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_a_postsyncer_run_that_created_no_post_moves_without_calling_postsyncer(): void
    {
        $at = $this->at();
        $video = $this->makeVideo([
            'status' => 'scheduled',
            'publish_state' => 'failed',
            'publish_progress' => ['version' => 1, 'state' => 'failed', 'current' => null, 'completed_groups' => [],
                'options' => ['when' => $at->toIso8601String(), 'platforms' => ['facebook', 'tiktok']]],
        ]);
        $unsafe = $this->makeVideo([
            'status' => 'scheduled',
            'publish_progress' => ['version' => 1, 'state' => 'uncertain', 'current' => ['phase' => 'creating'], 'completed_groups' => [],
                'options' => ['when' => $at->toIso8601String()]],
        ]);

        $this->artisan('socialreins:move-off-postsyncer', ['--apply' => true])
            ->expectsOutputToContain("{$video->human_id}  {$at->utc()->toIso8601String()}  postsyncer: -  to socialreins: english:facebook  left out: english:tiktok  => moved: handed to SocialReins")
            ->expectsOutputToContain("{$unsafe->human_id}  -  postsyncer: -  to socialreins: english:facebook  left out: english:tiktok  => skipped: a PostSyncer create may have gone out");
        $this->runQueued();

        $this->assertSame(['facebook'], $this->brainCreates()[0]['platforms']);
        $this->assertPostsyncerNotCalled();
    }

    public function test_the_settings_page_saves_the_switch(): void
    {
        $this->actingAsOwner();
        $this->get(route('settings.socialreins.edit'))->assertInertia(fn ($page) => $page->where('postsyncerEnabled', false));

        $this->put('/settings/socialreins', [
            'publish_driver' => 'socialreins',
            'postsyncer_enabled' => '1',
            'base_url' => 'https://brain.test/v1',
            'default_language' => 'english',
        ])->assertRedirect();

        $this->assertTrue(SocialreinsConfig::postsyncerEnabled($this->workspace->fresh()));
    }
}
