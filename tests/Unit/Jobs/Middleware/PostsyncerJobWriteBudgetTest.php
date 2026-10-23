<?php

namespace Tests\Unit\Jobs\Middleware;

use App\Jobs\Middleware\PostsyncerJobWriteBudget;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PostsyncerWriteThrottle;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class PostsyncerJobWriteBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const LOCK_KEY = 'postsyncer:write-throttle:lock';

    private float $now = 1_000.0;

    /** @var list<float> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'postsyncer.write_throttle.enabled' => true,
            'postsyncer.write_throttle.min_interval_seconds' => 20,
            'postsyncer.write_throttle.max_wait_seconds' => 60,
        ]);

        // A fake throttle in the container. The job middleware and the client both resolve it.
        $this->app->instance(PostsyncerWriteThrottle::class, new PostsyncerWriteThrottle(
            fn (): float => $this->now,
            function (float $seconds): void {
                $this->sleeps[] = $seconds;
                $this->now += $seconds;
            },
        ));
    }

    private function client(): PostsyncerClient
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, ['api_key' => 'test-api-key']);

        return new PostsyncerClient(PostsyncerConfig::fromWorkspace($workspace));
    }

    private function holdSlot(): Lock
    {
        $holder = Cache::lock(self::LOCK_KEY, 900);
        $this->assertTrue($holder->get());

        return $holder;
    }

    public function test_the_container_shares_one_throttle_per_scope(): void
    {
        $this->app->forgetInstance(PostsyncerWriteThrottle::class);
        $this->app->scoped(PostsyncerWriteThrottle::class);

        $this->assertSame(
            app(PostsyncerWriteThrottle::class),
            app(PostsyncerWriteThrottle::class),
        );
    }

    public function test_the_job_budget_caps_the_wait_of_a_client_write(): void
    {
        $holder = $this->holdSlot();
        Http::fake();

        $job = new StubPostsyncerJob(new StubQueueJob('job-budget'));
        $client = $this->client();

        // A 360-second job leaves 30 seconds after the HTTP timeout and margin.
        (new PostsyncerJobWriteBudget(360))->handle($job, fn () => $client->createPost(['content' => []]));

        $this->assertSame(30.0, array_sum($this->sleeps));
        $this->assertCount(1, $job->released);
        Http::assertNothingSent();

        $holder->release();
    }

    public function test_a_synchronous_write_waits_at_most_five_seconds(): void
    {
        $holder = $this->holdSlot();
        Http::fake();

        $client = $this->client();

        try {
            app(PostsyncerWriteThrottle::class)->withShortWait(fn () => $client->createPost(['content' => []]));
            $this->fail('Expected a busy slot to be refused.');
        } catch (PostsyncerException $exception) {
            $this->assertTrue($exception->notSent);
        }

        $this->assertSame(5.0, array_sum($this->sleeps));
        Http::assertNothingSent();

        $holder->release();
    }

    public function test_budget_state_does_not_leak_between_consecutive_jobs(): void
    {
        $holder = $this->holdSlot();
        Http::fake();
        $client = $this->client();

        $first = new StubPostsyncerJob(new StubQueueJob('first'));
        (new PostsyncerJobWriteBudget(360))->handle($first, fn () => $client->createPost(['content' => []]));
        $this->assertSame(30.0, array_sum($this->sleeps));

        // The second job has a full budget, so only the wait ceiling applies.
        $second = new StubPostsyncerJob(new StubQueueJob('second'));
        (new PostsyncerJobWriteBudget(900))->handle($second, fn () => $client->createPost(['content' => []]));

        $this->assertSame(90.0, array_sum($this->sleeps));

        $holder->release();
    }

    public function test_two_contending_jobs_release_the_waiter_without_failing_it(): void
    {
        $holder = $this->holdSlot();
        Http::fake();
        $client = $this->client();

        // The first job holds the slot. The waiter is released with a jittered delay.
        $waiter = new StubPostsyncerJob(new StubQueueJob('waiter'));
        $ran = false;
        (new PostsyncerJobWriteBudget(900))->handle($waiter, function () use ($client, &$ran): void {
            $ran = true;
            $client->createPost(['content' => []]);
        });

        $this->assertFalse($ran && $waiter->failed !== []);
        $this->assertCount(1, $waiter->released);
        $this->assertGreaterThanOrEqual(30, $waiter->released[0]);
        $this->assertLessThanOrEqual(90, $waiter->released[0]);
        $this->assertSame([], $waiter->failed);

        // The first job finishes, and the released job runs normally.
        $holder->release();
        $retry = new StubPostsyncerJob(new StubQueueJob('waiter'));
        $ranAgain = false;
        (new PostsyncerJobWriteBudget(900))->handle($retry, function () use (&$ranAgain): void {
            $ranAgain = true;
        });

        $this->assertTrue($ranAgain);
        $this->assertSame([], $retry->released);
    }

    public function test_real_failures_are_rethrown_until_the_try_budget_is_spent(): void
    {
        $job = new StubPostsyncerJob(new StubQueueJob('real-failures'));
        $job->tries = 2;
        $middleware = new PostsyncerJobWriteBudget(900);
        $failure = fn () => throw new PostsyncerException('PostSyncer API error 400: rejected');

        try {
            $middleware->handle($job, $failure);
            $this->fail('Expected the first real failure to be rethrown for backoff.');
        } catch (PostsyncerException) {
            $this->assertSame([], $job->failed);
        }

        $middleware->handle($job, $failure);

        $this->assertCount(1, $job->failed);
        $this->assertSame([], $job->released);
    }

    public function test_an_unexpected_exception_is_rethrown_without_failing_the_job(): void
    {
        $job = new StubPostsyncerJob(new StubQueueJob('unexpected'));

        $this->expectException(RuntimeException::class);
        (new PostsyncerJobWriteBudget(900))->handle($job, fn () => throw new RuntimeException('boom'));
    }
}

class StubQueueJob
{
    public function __construct(private readonly string $id) {}

    public function uuid(): string
    {
        return $this->id;
    }
}

class StubPostsyncerJob
{
    public int $tries = 3;

    /** @var list<int> */
    public array $released = [];

    /** @var list<mixed> */
    public array $failed = [];

    public function __construct(public ?object $job = null) {}

    public function release(int $delay = 0): void
    {
        $this->released[] = $delay;
    }

    public function fail(mixed $exception = null): void
    {
        $this->failed[] = $exception;
    }
}
