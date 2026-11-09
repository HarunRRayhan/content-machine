<?php

namespace Tests\Feature\Socialreins;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Actions\Postsyncer\EnqueuePostPublishAction;
use App\Actions\Postsyncer\EnqueueVideoPublishAction;
use App\Actions\Socialreins\EnqueueSocialreinsPublishAction;
use App\Actions\Socialreins\PublishToSocialreinsAction;
use App\Console\Commands\DispatchPendingPostPublishesCommand;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Jobs\PublishPostJob;
use App\Jobs\PublishSocialreinsJob;
use App\Jobs\PublishVideoJob;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesPublicDns;
use Tests\TestCase;

class SocialreinsLanguageRoutingTest extends TestCase
{
    use FakesPublicDns, RefreshDatabase;

    private const URL = 'https://brain.test/v1/post-groups';

    private const SET = '11111111-1111-4111-8111-111111111111';

    private const GROUP_ID = 'aaaaaaaa-0000-4000-8000-000000000001';

    private const POST_ID = 'bbbbbbbb-0000-4000-8000-000000000000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolveHostsTo();
        Queue::fake();
    }

    /** @param  list<string>|null  $routed  null leaves the setting absent */
    private function workspace(?array $routed = ['bangla'], string $driver = 'socialreins'): Workspace
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'publish_enabled' => true,
            'api_key' => 'k',
            'default_language' => 'english',
            'video_publish_enabled' => true,
            'languages' => [
                'english' => ['workspace_id' => '853', 'platforms' => []],
                'bangla' => ['workspace_id' => '854', 'platforms' => []],
            ],
            'post_types' => ['platforms' => [
                'facebook' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
                'twitter' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
            ]],
        ]);
        SocialreinsConfig::write($workspace, array_filter([
            'publish_driver' => $driver,
            'base_url' => 'https://brain.test/v1',
            'default_language' => 'english',
            'languages' => [
                'english' => ['set_id' => self::SET, 'token' => 'sr_set_live_english'],
                'bangla' => ['set_id' => self::SET, 'token' => 'sr_set_live_bangla'],
            ],
            'routed_languages' => $routed,
        ], fn (mixed $value): bool => $value !== null));

        return $workspace->fresh();
    }

    /** @param  array<string, mixed>  $attributes */
    private function makePost(Workspace $workspace, array $attributes = []): Post
    {
        return Post::factory()->for($workspace)->create([
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => ['caption' => 'Bangla text']],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
            'status' => 'ready',
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeVideo(Workspace $workspace, array $attributes = []): Video
    {
        return Video::factory()->for($workspace)->create([
            'language' => 'bn',
            'captions' => ['facebook' => ['caption' => 'Bangla text']],
            'status' => 'ready',
            ...$attributes,
        ]);
    }

    private function publishPost(Post $post, array $options = []): Post
    {
        return (new EnqueuePostPublishAction)->handle($post, $post->workspace->fresh(), $options);
    }

    private function assertWentToSocialreins(string $postsyncerJob): void
    {
        Queue::assertPushed(PublishSocialreinsJob::class, 1);
        Queue::assertNotPushed($postsyncerJob);
    }

    private function assertWentToPostsyncer(string $postsyncerJob): void
    {
        Queue::assertPushed($postsyncerJob, 1);
        Queue::assertNotPushed(PublishSocialreinsJob::class);
    }

    public function test_a_bangla_only_post_goes_through_socialreins(): void
    {
        $queued = $this->publishPost($this->makePost($this->workspace()));

        $this->assertWentToSocialreins(PublishPostJob::class);
        $this->assertSame('socialreins', $queued->publish_progress['driver']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function postsyncerPosts(): array
    {
        return [
            'english only' => [['language' => 'en', 'captions' => ['facebook' => ['caption' => 'English']]]],
            'both languages' => [['language' => 'both', 'captions' => [
                'English' => ['facebook' => ['caption' => 'EN']],
                'Bangla' => ['facebook' => ['caption' => 'BN']],
            ]]],
            'tagged english part on a bangla post' => [['language' => 'bn', 'captions' => [
                'English' => ['facebook' => ['caption' => 'EN']],
                'main' => ['facebook' => ['caption' => 'BN']],
            ]]],
            'no language with flat captions' => [['language' => null]],
            'both with untagged captions' => [['language' => 'both', 'captions' => ['main' => ['facebook' => ['caption' => 'x']]]]],
            'no captions' => [['captions' => null]],
        ];
    }

    #[DataProvider('postsyncerPosts')]
    public function test_a_post_with_a_non_routed_or_unknown_language_stays_on_postsyncer(array $attributes): void
    {
        $queued = $this->publishPost($this->makePost($this->workspace(), $attributes));

        $this->assertWentToPostsyncer(PublishPostJob::class);
        $this->assertArrayNotHasKey('driver', $queued->publish_progress);
    }

    public function test_narrowing_the_platforms_of_a_mixed_post_does_not_move_it_to_socialreins(): void
    {
        $post = $this->makePost($this->workspace(), ['language' => 'both', 'platforms' => ['facebook', 'twitter'], 'captions' => [
            'English' => ['twitter' => ['caption' => 'EN']],
            'Bangla' => ['facebook' => ['caption' => 'BN']],
        ]]);

        $this->publishPost($post, ['platforms' => ['facebook']]);

        $this->assertWentToPostsyncer(PublishPostJob::class);
    }

    public function test_a_caption_language_outside_the_post_platforms_still_counts(): void
    {
        // Publish options may name any platform, so captions decide, not post.platforms.
        $post = $this->makePost($this->workspace(), ['language' => 'both', 'platforms' => ['facebook'], 'captions' => [
            'English' => ['twitter' => ['caption' => 'EN']],
            'Bangla' => ['facebook' => ['caption' => 'BN']],
        ]]);

        $this->publishPost($post);

        $this->assertWentToPostsyncer(PublishPostJob::class);
    }

    /** @return array<string, array{array<int, string>|null}> */
    public static function noRouting(): array
    {
        return ['absent' => [null], 'empty' => [[]]];
    }

    #[DataProvider('noRouting')]
    public function test_without_a_routed_list_every_language_still_goes_through_socialreins(?array $routed): void
    {
        $workspace = $this->workspace($routed);

        $this->publishPost($this->makePost($workspace, ['language' => 'both', 'captions' => [
            'English' => ['facebook' => ['caption' => 'EN']],
            'Bangla' => ['facebook' => ['caption' => 'BN']],
        ]]));

        $this->assertWentToSocialreins(PublishPostJob::class);
    }

    public function test_the_postsyncer_driver_ignores_the_routed_list(): void
    {
        $this->publishPost($this->makePost($this->workspace(['bangla'], 'postsyncer')));

        $this->assertWentToPostsyncer(PublishPostJob::class);
    }

    public function test_a_bangla_video_goes_through_socialreins(): void
    {
        $video = $this->makeVideo($this->workspace());

        (new EnqueueVideoPublishAction)->handle($video, $video->workspace, []);

        $this->assertWentToSocialreins(PublishVideoJob::class);
    }

    /** @return array<string, array{string|null}> */
    public static function postsyncerVideoLanguages(): array
    {
        return ['english' => ['en'], 'unknown' => [null], 'both' => ['both']];
    }

    #[DataProvider('postsyncerVideoLanguages')]
    public function test_an_english_or_unknown_video_stays_on_postsyncer(?string $language): void
    {
        $video = $this->makeVideo($this->workspace(), ['language' => $language]);

        (new EnqueueVideoPublishAction)->handle($video, $video->workspace, []);

        $this->assertWentToPostsyncer(PublishVideoJob::class);
    }

    public function test_a_failed_socialreins_publish_retries_there_after_the_setting_changes(): void
    {
        $this->fakeHttp([
            self::URL => Http::response(['error' => 'internal'], 500),
            'brain.test/v1/idempotency/*' => Http::response(['error' => 'not_found'], 404),
        ]);
        $workspace = $this->workspace();
        $post = $this->makePost($workspace);
        $this->publishPost($post);
        app(PublishToSocialreinsAction::class)->handle($post);
        $this->assertSame('failed', $post->fresh()->publish_state);
        Queue::fake();

        SocialreinsConfig::write($workspace, ['publish_driver' => 'postsyncer']);
        $this->publishPost($post->fresh());

        $this->assertWentToSocialreins(PublishPostJob::class);
        $this->assertSame(1, SocialreinsGroup::query()->count());
    }

    public function test_a_failed_socialreins_video_retries_there_after_the_setting_changes(): void
    {
        $workspace = $this->workspace();
        $video = $this->makeVideo($workspace, ['publish_state' => 'failed', 'publish_progress' => [
            'driver' => 'socialreins', 'state' => 'failed', 'options' => [],
        ]]);

        SocialreinsConfig::write($workspace, ['publish_driver' => 'postsyncer']);
        (new EnqueueVideoPublishAction)->handle($video, $workspace->fresh(), []);

        $this->assertWentToSocialreins(PublishVideoJob::class);
    }

    /** @return array<string, array{string}> */
    public static function takeDownKinds(): array
    {
        return ['post' => ['post'], 'video' => ['video']];
    }

    #[DataProvider('takeDownKinds')]
    public function test_a_subject_live_on_socialreins_keeps_take_down_after_the_setting_changes(string $kind): void
    {
        $this->fakeHttp(['brain.test/v1/posts/*' => Http::response(null, 204)]);
        $workspace = $this->workspace();
        $subject = $kind === 'video' ? $this->makeVideo($workspace) : $this->makePost($workspace);
        $this->liveGroup($subject, $kind);
        SocialreinsConfig::write($workspace, ['publish_driver' => 'postsyncer']);
        $token = (new CreateWorkspaceApiTokenAction)->handle(
            $workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('test client'),
        )['plaintext'];

        $this->withToken($token)->postJson("/api/v1/{$kind}s/{$subject->human_id}/take-down")
            ->assertOk()
            ->assertJsonPath('taken_down', true);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_take_down_refuses_a_subject_that_is_on_postsyncer(): void
    {
        $workspace = $this->workspace();
        $post = $this->makePost($workspace, ['postsyncer' => ['groups' => [['post_id' => '99', 'language' => 'bangla', 'platforms' => ['facebook']]]]]);
        $token = (new CreateWorkspaceApiTokenAction)->handle(
            $workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('test client'),
        )['plaintext'];

        $this->withToken($token)->postJson("/api/v1/posts/{$post->human_id}/take-down")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('take_down');
    }

    public function test_a_subject_with_postsyncer_groups_is_never_sent_to_socialreins(): void
    {
        $post = $this->makePost($this->workspace(), ['postsyncer' => ['groups' => [['post_id' => '99', 'language' => 'bangla', 'platforms' => ['facebook']]]]]);

        try {
            $this->publishPost($post);
            $this->fail('A second publish of a PostSyncer post must be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('publish', $exception->errors());
        }

        Queue::assertNotPushed(PublishSocialreinsJob::class);
    }

    public function test_a_failed_postsyncer_run_retries_on_postsyncer_after_bangla_is_routed(): void
    {
        $post = $this->makePost($this->workspace(), [
            'publish_state' => 'failed',
            'publish_progress' => ['version' => 1, 'operation_id' => 'op', 'run_token' => 't', 'options' => [], 'plan_hash' => null, 'planned_groups' => [], 'completed_groups' => [], 'current' => null, 'state' => 'failed'],
        ]);

        $this->publishPost($post);

        $this->assertWentToPostsyncer(PublishPostJob::class);
    }

    public function test_the_socialreins_enqueue_refuses_a_subject_postsyncer_already_has(): void
    {
        $post = $this->makePost($this->workspace(), ['postsyncer' => ['groups' => [['post_id' => '99']]]]);

        $this->expectException(ValidationException::class);
        app(EnqueueSocialreinsPublishAction::class)->handle($post, $post->workspace, []);
    }

    private function telegramRequest(Post $post): TelegramPostRequest
    {
        $config = TelegramBotConfig::factory()->for($post->workspace)->connected()->create();

        return TelegramPostRequest::factory()->for($post->workspace)->create([
            'telegram_bot_config_id' => $config->id,
            'post_id' => $post->id,
            'state' => TelegramPostRequest::APPROVED,
        ]);
    }

    public function test_a_telegram_publish_of_a_routed_post_stays_on_postsyncer(): void
    {
        $post = $this->makePost($this->workspace());
        $request = $this->telegramRequest($post);

        $this->publishPost($post, ['telegram_request_id' => $request->id]);

        $this->assertWentToPostsyncer(PublishPostJob::class);
        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job): bool => $job->options['telegram_request_id'] === $request->id);
        $this->assertArrayNotHasKey('driver', $post->fresh()->publish_progress);
    }

    public function test_a_telegram_publish_of_a_post_already_on_socialreins_stays_there(): void
    {
        $post = $this->makePost($this->workspace(), ['publish_state' => 'failed', 'publish_progress' => [
            'driver' => 'socialreins', 'state' => 'failed', 'options' => [],
        ]]);

        $this->publishPost($post, ['telegram_request_id' => $this->telegramRequest($post)->id]);

        $this->assertWentToSocialreins(PublishPostJob::class);
    }

    public function test_a_telegram_publish_without_a_routed_list_keeps_going_through_socialreins(): void
    {
        $post = $this->makePost($this->workspace(null));

        $this->publishPost($post, ['telegram_request_id' => $this->telegramRequest($post)->id]);

        $this->assertWentToSocialreins(PublishPostJob::class);
    }

    public function test_the_socialreins_recheck_reads_the_locked_workspace(): void
    {
        $workspace = $this->workspace();
        $post = $this->makePost($workspace);
        $stale = $workspace->fresh();
        SocialreinsConfig::write($workspace, ['publish_driver' => 'postsyncer']);

        $this->expectException(ValidationException::class);

        try {
            app(EnqueueSocialreinsPublishAction::class)->handle($post, $stale, []);
        } finally {
            Queue::assertNotPushed(PublishSocialreinsJob::class);
        }
    }

    public function test_a_stale_postsyncer_decision_is_refused_once_socialreins_owns_the_post(): void
    {
        $post = $this->makePost($this->workspace(), ['language' => 'en']);
        Post::query()->whereKey($post->id)->update([
            'publish_progress' => json_encode(['driver' => 'socialreins', 'state' => 'failed', 'options' => []]),
            'publish_state' => 'failed',
        ]);

        $this->expectException(ValidationException::class);

        try {
            $this->publishPost($post);
        } finally {
            Queue::assertNotPushed(PublishPostJob::class);
        }
    }

    public function test_a_subject_fully_taken_down_follows_the_settings_again(): void
    {
        $workspace = $this->workspace();
        $post = $this->makePost($workspace);
        $this->liveGroup($post, 'post', 'deleted');
        SocialreinsConfig::write($workspace, ['publish_driver' => 'postsyncer']);

        $this->publishPost($post);

        $this->assertWentToPostsyncer(PublishPostJob::class);
    }

    public function test_the_recovery_sweep_only_picks_up_postsyncer_rows_of_a_mixed_workspace(): void
    {
        $workspace = $this->workspace();
        $socialreins = $this->makePost($workspace);
        $english = $this->makePost($workspace, ['language' => 'en']);
        $this->publishPost($socialreins);
        $this->publishPost($english);
        Queue::fake();
        Post::query()->whereKey([$socialreins->id, $english->id])->update(['publish_claimed_at' => now()->subDay()]);

        $this->artisan(DispatchPendingPostPublishesCommand::class)->assertSuccessful();

        Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job): bool => $job->post->is($english));
        Queue::assertPushed(PublishPostJob::class, 1);
    }

    public function test_with_only_bangla_set_and_routed_a_bangla_post_is_not_refused_for_a_missing_english_set(): void
    {
        $workspace = Workspace::factory()->create();
        SocialreinsConfig::write($workspace, [
            'publish_driver' => 'socialreins',
            'base_url' => 'https://brain.test/v1',
            'default_language' => 'english',
            'routed_languages' => ['bangla'],
            'languages' => ['bangla' => ['set_id' => self::SET, 'token' => 'sr_set_live_bangla']],
        ]);

        $this->assertTrue(SocialreinsConfig::fromWorkspace($workspace->fresh())->isReadyForPublish());
        $this->publishPost($this->makePost($workspace->fresh()));

        $this->assertWentToSocialreins(PublishPostJob::class);
    }

    public function test_the_show_pages_report_each_subject_on_its_own_driver(): void
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, ['video_publish_enabled' => false]);
        SocialreinsConfig::write($workspace, [
            'publish_driver' => 'socialreins',
            'base_url' => 'https://brain.test/v1',
            'routed_languages' => ['bangla'],
            'languages' => ['bangla' => ['set_id' => self::SET, 'token' => 'sr_set_live_bangla']],
        ]);
        $user = User::factory()->create(['current_team_id' => $workspace->team_id]);
        $workspace->team->members()->attach($user->id, ['role' => 'owner']);
        $this->actingAs($user);
        $workspace = $workspace->fresh();

        $this->get(route('posts.show', $this->makePost($workspace)))
            ->assertInertia(fn (Assert $page) => $page->where('post.postsyncer_ready', true));
        $this->get(route('posts.show', $this->makePost($workspace, ['language' => 'en'])))
            ->assertInertia(fn (Assert $page) => $page->where('post.postsyncer_ready', false));
        $this->get(route('videos.show', $this->makeVideo($workspace)))
            ->assertInertia(fn (Assert $page) => $page
                ->where('video.postsyncer_ready', true)
                ->where('video.video_publish_enabled', true));
        $this->get(route('videos.show', $this->makeVideo($workspace, ['language' => 'en'])))
            ->assertInertia(fn (Assert $page) => $page
                ->where('video.postsyncer_ready', false)
                ->where('video.video_publish_enabled', false));
    }

    private function liveGroup(Post|Video $subject, string $kind, string $status = 'published'): void
    {
        SocialreinsGroup::query()->create([
            'workspace_id' => $subject->workspace_id,
            'subject_type' => $kind,
            'subject_id' => $subject->getKey(),
            'language' => 'bangla',
            'sequence' => 1,
            'idempotency_key' => "cm:test:{$kind}:{$subject->getKey()}",
            'state' => SocialreinsGroup::CREATED,
            'request' => '{}',
            'group_id' => self::GROUP_ID,
            'expected_targets' => 1,
            'results' => [self::POST_ID => [
                'platform' => 'facebook', 'status' => $status, 'error' => null, 'at' => time(),
                'targets' => ['t1' => ['status' => $status, 'remote_id' => 'r', 'remote_url' => null, 'parts' => 0, 'error' => null, 'at' => time(), 'unknown' => false]],
            ]],
        ]);
    }
}
