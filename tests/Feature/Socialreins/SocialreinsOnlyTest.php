<?php

namespace Tests\Feature\Socialreins;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Actions\Socialreins\EnqueueSocialreinsPublishAction;
use App\Actions\Socialreins\PublishToSocialreinsAction;
use App\Actions\Socialreins\RescheduleSocialreinsAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Data\Socialreins\ReschedulePostData;
use App\Jobs\PublishSocialreinsJob;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Publishing\PublishException;
use App\Support\Socialreins\SocialreinsConfig;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakesPublicDns;
use Tests\TestCase;

/** Everything publishes through SocialReins; what it does not cover is left out, and PostSyncer history is never sent again. */
class SocialreinsOnlyTest extends TestCase
{
    use FakesPublicDns, RefreshDatabase;

    private const SET = '11111111-1111-4111-8111-111111111111';

    private const SR_URL = 'https://brain.test/v1/post-groups';

    private Workspace $workspace;

    /** @var list<array{0: array<string, mixed>, 1: int}> successive brain answers to POST /post-groups */
    private array $brainAnswers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolveHostsTo();
        Queue::fake();

        // The post-type matrix saved under settings.postsyncer is still what the planner reads.
        $this->workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka', 'settings' => ['postsyncer' => [
            'post_types' => ['platforms' => [
                'facebook' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
                'linkedin' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
                'tiktok' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
            ]],
        ]]]);
        SocialreinsConfig::write($this->workspace, [
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
     * As a PostSyncer publish left an item before PostSyncer was removed: group 42 scheduled at $at.
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

    public function test_a_post_with_tiktok_goes_to_socialreins_without_tiktok(): void
    {
        $post = $this->makePost();

        $queued = app(EnqueueSocialreinsPublishAction::class)->handle($post, $this->workspace, ['when' => $this->at()->toIso8601String()]);

        Queue::assertPushed(PublishSocialreinsJob::class, 1);
        $this->assertSame(['english:tiktok'], $queued->publish_progress['left_out']);

        $this->runQueued();

        [$create] = $this->brainCreates();
        $this->assertSame(['facebook'], $create['platforms']);
        $this->assertSame('scheduled', $post->fresh()->status);
        $this->assertSame(['english:tiktok'], $post->fresh()->publish_progress['left_out']);
    }

    public function test_a_video_with_tiktok_goes_to_socialreins_without_tiktok(): void
    {
        $video = $this->makeVideo();

        $queued = app(EnqueueSocialreinsPublishAction::class)->handle($video, $this->workspace, ['when' => $this->at()->toIso8601String()]);

        $this->assertSame(['english:tiktok'], $queued->publish_progress['left_out']);

        $this->runQueued();

        [$create] = $this->brainCreates();
        $this->assertSame(['facebook'], $create['platforms']);
        $this->assertSame('scheduled', $video->fresh()->status);

        $this->actingAsOwner();
        $this->get(route('videos.show', $video))
            ->assertInertia(fn ($page) => $page->where('video.publish_left_out', ['english:tiktok']));
    }

    public function test_an_item_with_nothing_socialreins_covers_is_refused_with_a_clear_message(): void
    {
        $post = $this->makePost(['tiktok']);

        try {
            app(EnqueueSocialreinsPublishAction::class)->handle($post, $this->workspace);
            $this->fail('Expected a refusal.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Nothing to publish: SocialReins does not cover english:tiktok.', $exception->getMessage());
        }

        Queue::assertNothingPushed();
    }

    public function test_an_item_published_through_postsyncer_is_not_published_again(): void
    {
        $post = $this->makePost(attributes: [...$this->postsyncerOnly(['facebook'], $this->at()), 'publish_state' => 'failed']);

        $this->expectExceptionMessage(SocialreinsConfig::POSTSYNCER_HISTORY_MESSAGE);

        try {
            app(EnqueueSocialreinsPublishAction::class)->handle($post, $this->workspace);
        } finally {
            Queue::assertNothingPushed();
        }
    }

    public function test_a_language_with_no_saved_platform_list_covers_nothing(): void
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

    public function test_a_named_platform_a_live_language_has_no_post_for_is_sent_alone(): void
    {
        $this->brainAnswers = [[$this->brainGroup(['linkedin'], '2'), 201]];
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

        // Facebook is live already: only the named platform it has no post for goes out.
        $this->assertSame(['linkedin'], $this->brainCreates()[0]['platforms']);
    }

    public function test_reschedule_and_take_down_refuse_an_item_published_through_postsyncer(): void
    {
        $post = $this->makePost(attributes: $this->postsyncerOnly(['facebook'], $this->at()));
        $video = $this->makeVideo($this->postsyncerOnly(['facebook'], $this->at()));
        $data = new ReschedulePostData($this->at()->addDay());

        foreach ([
            fn () => app(RescheduleSocialreinsAction::class)->handle($post, $this->workspace, $data),
            fn () => app(RescheduleSocialreinsAction::class)->handle($video, $this->workspace, $data),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected a refusal.');
            } catch (PublishException $exception) {
                $this->assertSame(SocialreinsConfig::POSTSYNCER_HISTORY_MESSAGE, $exception->getMessage());
            }
        }

        $token = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('test client'),
        )['plaintext'];
        $this->withToken($token)->postJson("/api/v1/posts/{$post->human_id}/take-down")
            ->assertUnprocessable()
            ->assertJsonPath('errors.take_down.0', SocialreinsConfig::POSTSYNCER_HISTORY_MESSAGE);

        Http::assertNothingSent();
    }

    public function test_a_failed_postsyncer_run_that_created_no_post_publishes_through_socialreins(): void
    {
        $video = $this->makeVideo([
            'publish_state' => 'failed',
            'publish_progress' => ['version' => 1, 'state' => 'failed', 'current' => null, 'completed_groups' => [],
                'options' => ['when' => $this->at()->toIso8601String(), 'platforms' => ['facebook', 'tiktok']]],
        ]);
        $unsafe = $this->makeVideo([
            'publish_state' => 'failed',
            'publish_progress' => ['version' => 1, 'state' => 'uncertain', 'current' => ['phase' => 'creating'], 'completed_groups' => [],
                'options' => ['when' => $this->at()->toIso8601String()]],
        ]);

        app(EnqueueSocialreinsPublishAction::class)->handle($video, $this->workspace, ['when' => $this->at()->toIso8601String()]);
        $this->runQueued();
        $this->assertSame(['facebook'], $this->brainCreates()[0]['platforms']);

        $this->expectExceptionMessage(SocialreinsConfig::POSTSYNCER_HISTORY_MESSAGE);
        app(EnqueueSocialreinsPublishAction::class)->handle($unsafe, $this->workspace);
    }

    public function test_a_telegram_publish_goes_through_socialreins_with_its_request(): void
    {
        $post = $this->makePost(['facebook']);
        $request = TelegramPostRequest::factory()->for($this->workspace)->create([
            'telegram_bot_config_id' => TelegramBotConfig::factory()->for($this->workspace)->connected()->create()->id,
            'post_id' => $post->id,
            'state' => TelegramPostRequest::APPROVED,
        ]);

        app(EnqueueSocialreinsPublishAction::class)->handle($post, $this->workspace, ['telegram_request_id' => $request->id]);

        Queue::assertPushed(PublishSocialreinsJob::class, fn (PublishSocialreinsJob $job): bool => $job->options['telegram_request_id'] === $request->id);
        $this->assertSame($request->id, $post->fresh()->publish_progress['options']['telegram_request_id']);
    }

    public function test_the_show_pages_say_whether_socialreins_is_ready(): void
    {
        $this->actingAsOwner();

        $this->get(route('posts.show', $this->makePost()))
            ->assertInertia(fn ($page) => $page->where('post.publish_ready', true));
        $this->get(route('videos.show', $this->makeVideo()))
            ->assertInertia(fn ($page) => $page->where('video.publish_ready', true));

        SocialreinsConfig::write($this->workspace, ['languages' => ['english' => ['set_id' => null]]]);

        $this->get(route('posts.show', $this->makePost()))
            ->assertInertia(fn ($page) => $page->where('post.publish_ready', false));
        $this->get(route('videos.show', $this->makeVideo()))
            ->assertInertia(fn ($page) => $page->where('video.publish_ready', false));
    }
}
