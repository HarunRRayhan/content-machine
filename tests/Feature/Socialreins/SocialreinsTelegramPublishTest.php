<?php

namespace Tests\Feature\Socialreins;

use App\Actions\Posts\ApprovePostAction;
use App\Actions\Socialreins\EnqueueSocialreinsPublishAction;
use App\Actions\Socialreins\PublishToSocialreinsAction;
use App\Actions\Socialreins\SettleSocialreinsSubjectAction;
use App\Actions\Telegram\CancelTelegramPostRequestAction;
use App\Jobs\PublishSocialreinsJob;
use App\Models\Post;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramPostRequest;
use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakesPublicDns;
use Tests\TestCase;

/** A Telegram publish through SocialReins admits, locks and closes its request. */
class SocialreinsTelegramPublishTest extends TestCase
{
    use FakesPublicDns, RefreshDatabase;

    private const SR_URL = 'https://brain.test/v1/post-groups';

    private const SET = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolveHostsTo();
        Queue::fake();
    }

    private function workspace(): Workspace
    {
        // The post-type matrix saved under settings.postsyncer is still what the planner reads.
        $workspace = Workspace::factory()->create(['settings' => ['postsyncer' => [
            'post_types' => ['platforms' => [
                'facebook' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
                'linkedin' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
            ]],
        ]]]);
        SocialreinsConfig::write($workspace, [
            'base_url' => 'https://brain.test/v1',
            'default_language' => 'english',
            'languages' => ['english' => [
                'set_id' => self::SET,
                'token' => 'sr_set_live_english',
                'platforms' => ['facebook', 'linkedin'],
            ]],
        ]);

        return $workspace->fresh();
    }

    private function makePost(Workspace $workspace): Post
    {
        return Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['facebook', 'linkedin'],
            'captions' => ['English' => [
                'facebook' => ['caption' => 'EN fb'],
                'linkedin' => ['caption' => 'EN li'],
            ]],
            'image_drive_urls' => [],
            'status' => 'ready',
        ]);
    }

    /**
     * @param  list<mixed>  $srAnswers  brain answers for successive `POST /post-groups`
     * @param  array<string, mixed>  $stubs  replace the answer of a URL
     */
    private function fakeApis(array $srAnswers = [], array $stubs = []): void
    {
        $sr = Http::sequence();
        foreach ($srAnswers ?: [[$this->srGroup(), 201]] as [$body, $status]) {
            $sr->push($body, $status);
        }

        $this->fakeHttp($stubs + [
            self::SR_URL => $sr,
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

    private function publish(Post $post, array $options = []): Post
    {
        return app(EnqueueSocialreinsPublishAction::class)->handle($post, $post->workspace->fresh(), $options);
    }

    private function telegramRequest(Post $post): TelegramPostRequest
    {
        $config = TelegramBotConfig::factory()->for($post->workspace)->connected()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'telegram_user_id' => 42]);

        return TelegramPostRequest::factory()->for($post->workspace)->create([
            'telegram_bot_config_id' => $config->id,
            'telegram_user_id' => 42,
            'telegram_chat_id' => 555,
            'post_id' => $post->id,
            'state' => TelegramPostRequest::APPROVED,
        ]);
    }

    public function test_a_telegram_publish_closes_its_request_as_published(): void
    {
        $this->fakeApis();
        $post = $this->makePost($this->workspace());
        $request = $this->telegramRequest($post);

        $queued = $this->publish($post, ['telegram_request_id' => $request->id]);

        Queue::assertPushed(PublishSocialreinsJob::class, fn (PublishSocialreinsJob $job): bool => $job->options['telegram_request_id'] === $request->id);
        $this->assertSame($request->id, $queued->publish_progress['options']['telegram_request_id']);
        $this->assertSame(TelegramPostRequest::APPROVED, $request->fresh()->state);

        $this->runQueued();

        // Handed over and scheduled counts as published.
        $this->assertSame('scheduled', $post->fresh()->status);
        $this->assertSame(TelegramPostRequest::PUBLISHED, $request->fresh()->state);

        // /cancel finds nothing to cancel: the scheduled post stays, and so does the request.
        app(CancelTelegramPostRequestAction::class)->handle($request, $request->telegramBotConfig, 42, 555);
        $this->assertSame(TelegramPostRequest::PUBLISHED, $request->fresh()->state);
    }

    public function test_a_failed_socialreins_telegram_publish_fails_its_request_and_a_retry_reopens_it(): void
    {
        $this->fakeApis([[['error' => 'too_long'], 422], [$this->srGroup(), 201]]);
        $post = $this->makePost($this->workspace());
        $request = $this->telegramRequest($post);

        $this->publish($post, ['telegram_request_id' => $request->id]);
        $this->runQueued();

        $this->assertSame('failed', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::FAILED, $request->fresh()->state);
        $this->assertNotNull($request->fresh()->error_message);

        // A dashboard retry carries no request id: the post's failed request is picked up.
        $queued = $this->publish($post->fresh());

        $this->assertSame(TelegramPostRequest::APPROVED, $request->fresh()->state);
        $this->assertNull($request->fresh()->error_message);
        $this->assertSame($request->id, $queued->publish_progress['options']['telegram_request_id']);

        $this->runQueued();

        $this->assertSame('succeeded', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::PUBLISHED, $request->fresh()->state);
    }

    public function test_a_settle_after_the_job_still_finds_the_request(): void
    {
        $this->fakeApis();
        $post = $this->makePost($this->workspace());
        $request = $this->telegramRequest($post);
        $this->publish($post, ['telegram_request_id' => $request->id]);
        $this->runQueued();
        $request->forceFill(['state' => TelegramPostRequest::FAILED])->save();

        // What a webhook or the reconcile runs: no publish options, only the stored ones.
        app(SettleSocialreinsSubjectAction::class)->handle($post->fresh());

        $this->assertSame(TelegramPostRequest::PUBLISHED, $request->fresh()->state);
    }

    public function test_a_late_settle_leaves_a_request_the_user_moved_back_to_awaiting_approval(): void
    {
        $this->fakeApis([[['error' => 'too_long'], 422]]);
        $post = $this->makePost($this->workspace());
        $request = $this->telegramRequest($post);
        $this->publish($post, ['telegram_request_id' => $request->id]);
        $this->runQueued();
        $this->assertSame(TelegramPostRequest::FAILED, $request->fresh()->state);

        // The user edits the failed post: the approval and the request are open again.
        $post->refresh()->forceFill(['approval_state' => 'approved'])->save();
        $post->invalidateApproval();
        $this->assertSame(TelegramPostRequest::AWAITING_APPROVAL, $request->fresh()->state);

        // The next webhook or reconcile settles the old run as failed once more.
        app(SettleSocialreinsSubjectAction::class)->handle($post->fresh());

        $this->assertSame('failed', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::AWAITING_APPROVAL, $request->fresh()->state);

        // So /approve still works, and a settle before the new publish starts keeps it approved.
        $link = TelegramBotLink::query()->where('telegram_user_id', 42)->firstOrFail();
        app(ApprovePostAction::class)->handle($post->fresh(), $link->user, $request->fresh(), $request->telegramBotConfig, 42, 555);
        $this->assertSame(TelegramPostRequest::APPROVED, $request->fresh()->state);

        app(SettleSocialreinsSubjectAction::class)->handle($post->fresh());

        $this->assertSame(TelegramPostRequest::APPROVED, $request->fresh()->state);
    }

    public function test_a_dashboard_retry_is_not_refused_when_the_bot_was_disconnected(): void
    {
        $this->fakeApis();
        $post = $this->makePost($this->workspace());
        $request = $this->telegramRequest($post);
        $this->publish($post, ['telegram_request_id' => $request->id]);
        $request->telegramBotConfig->forceFill(['bot_token' => null])->save();

        // Nothing is live yet, so the run stops and fails its request.
        $this->runQueued();

        $this->assertSame([], $this->sentTo(self::SR_URL));
        $this->assertSame('failed', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::FAILED, $request->fresh()->state);

        // A publish that names the request keeps the strict check.
        try {
            $this->publish($post->fresh(), ['telegram_request_id' => $request->id]);
            $this->fail('A named request of a disconnected bot must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('no longer approved', $exception->getMessage());
        }

        // The dashboard retry names none: it goes out without the request.
        $queued = $this->publish($post->fresh());

        $this->assertArrayNotHasKey('telegram_request_id', $queued->publish_progress['options']);
        // Only the retry's own job: the first run's job is long gone from a real queue.
        Queue::pushed(PublishSocialreinsJob::class)->last()->handle(app(PublishToSocialreinsAction::class));
        $this->assertCount(1, $this->sentTo(self::SR_URL));
        $this->assertSame('succeeded', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::FAILED, $request->fresh()->state);
    }

    public function test_a_dashboard_retry_is_not_refused_when_the_post_has_two_failed_requests(): void
    {
        $this->fakeApis([[['error' => 'too_long'], 422], [$this->srGroup(), 201]]);
        $post = $this->makePost($this->workspace());
        $request = $this->telegramRequest($post);
        $this->publish($post, ['telegram_request_id' => $request->id]);
        $this->runQueued();
        $other = $request->fresh()->replicate()->fill(['telegram_update_key' => 'other']);
        $other->save();
        $this->assertSame(TelegramPostRequest::FAILED, $other->fresh()->state);

        $queued = $this->publish($post->fresh());

        $this->assertArrayNotHasKey('telegram_request_id', $queued->publish_progress['options']);
        // Only the retry's own job: the first run's job is long gone from a real queue.
        Queue::pushed(PublishSocialreinsJob::class)->last()->handle(app(PublishToSocialreinsAction::class));
        $this->assertSame('succeeded', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::FAILED, $request->fresh()->state);
        $this->assertSame(TelegramPostRequest::FAILED, $other->fresh()->state);
    }

    public function test_a_cancelled_telegram_request_is_not_published_through_socialreins(): void
    {
        $this->fakeApis();
        $post = $this->makePost($this->workspace());
        $request = $this->telegramRequest($post);
        $this->publish($post, ['telegram_request_id' => $request->id]);
        $request->forceFill(['state' => TelegramPostRequest::CANCELLED])->save();

        $this->runQueued();

        $this->assertSame([], $this->sentTo(self::SR_URL));
        $this->assertSame('idle', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::CANCELLED, $request->fresh()->state);
    }

    public function test_a_socialreins_job_that_fails_for_good_fails_the_telegram_request(): void
    {
        $post = $this->makePost($this->workspace());
        $request = $this->telegramRequest($post);
        $queued = $this->publish($post, ['telegram_request_id' => $request->id]);

        (new PublishSocialreinsJob($queued, $queued->publish_progress['options']))->failed(null);

        $this->assertSame('failed', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::FAILED, $request->fresh()->state);
    }

    public function test_a_webhook_settle_while_the_job_is_still_working_changes_nothing(): void
    {
        $post = $this->makePost($this->workspace());
        $request = $this->telegramRequest($post);
        $seen = null;
        // The settle a webhook runs lands while the job waits for brain's answer to the create.
        $this->fakeApis(stubs: [self::SR_URL => function () use ($post, $request, &$seen) {
            app(SettleSocialreinsSubjectAction::class)->handle($post->fresh());
            $seen = [$post->fresh()->publish_state, $request->fresh()->state];

            return Http::response($this->srGroup(), 201);
        }]);
        $this->publish($post, ['telegram_request_id' => $request->id]);

        $this->runQueued();

        $this->assertSame(['running', TelegramPostRequest::APPROVED], $seen);
        $this->assertSame('succeeded', $post->fresh()->publish_state);
        $this->assertSame(TelegramPostRequest::PUBLISHED, $request->fresh()->state);
    }
}
