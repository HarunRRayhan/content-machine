<?php

namespace Tests\Unit\Support\Postsyncer;

use App\Jobs\PublishPostJob;
use App\Jobs\PublishVideoJob;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PostsyncerWriteThrottle;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Mockery;
use ReflectionClassConstant;
use RuntimeException;
use Tests\TestCase;

class PostsyncerWriteThrottleTest extends TestCase
{
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
    }

    private function throttle(): PostsyncerWriteThrottle
    {
        return new PostsyncerWriteThrottle(
            fn (): float => $this->now,
            function (float $seconds): void {
                $this->sleeps[] = $seconds;
                $this->now += $seconds;
            },
        );
    }

    public function test_spacing_is_enforced_between_two_writes(): void
    {
        $starts = [];
        $throttle = $this->throttle();

        $throttle->run(function () use (&$starts): void {
            $starts[] = $this->now;
        });
        $throttle->run(function () use (&$starts): void {
            $starts[] = $this->now;
        });

        $this->assertSame([1_000.0, 1_020.0], $starts);
        $this->assertSame([20.0], $this->sleeps);
    }

    public function test_a_write_after_the_interval_does_not_wait(): void
    {
        $throttle = $this->throttle();
        $throttle->run(fn (): bool => true);

        $this->now += 60;
        $throttle->run(fn (): bool => true);

        $this->assertSame([], $this->sleeps);
    }

    public function test_concurrent_writes_are_serialized_and_refused_before_sending_when_the_slot_stays_busy(): void
    {
        $holder = Cache::lock(self::LOCK_KEY, 900);
        $this->assertTrue($holder->get());

        $writes = 0;
        try {
            $this->throttle()->run(function () use (&$writes): void {
                $writes++;
            });
            $this->fail('Expected a busy throttle to refuse the write.');
        } catch (PostsyncerException $exception) {
            $this->assertTrue($exception->notSent);
            $this->assertTrue($exception->retryable);
            $this->assertTrue($exception->safeToRetry);
            $this->assertFalse($exception->responseReceived);
            $this->assertFalse($exception->outcomeUnknown);
        }

        $this->assertSame(0, $writes);
        $this->assertSame(60.0, array_sum($this->sleeps));

        $holder->release();
    }

    public function test_a_nested_write_cannot_overlap_the_outer_write(): void
    {
        $throttle = new PostsyncerWriteThrottle(
            fn (): float => $this->now,
            function (float $seconds): void {
                $this->now += $seconds;
            },
        );
        config(['postsyncer.write_throttle.max_wait_seconds' => 0]);

        $innerRefused = false;
        $throttle->run(function () use ($throttle, &$innerRefused): void {
            try {
                $throttle->run(fn (): bool => true);
            } catch (PostsyncerException $exception) {
                $innerRefused = $exception->notSent;
            }
        });

        $this->assertTrue($innerRefused);
    }

    public function test_disabled_flag_bypasses_the_throttle(): void
    {
        config(['postsyncer.write_throttle.enabled' => false]);

        $holder = Cache::lock(self::LOCK_KEY, 900);
        $this->assertTrue($holder->get());

        $throttle = $this->throttle();
        $throttle->run(fn (): bool => true);
        $throttle->run(fn (): bool => true);

        $this->assertSame([], $this->sleeps);

        $holder->release();
    }

    public function test_zero_interval_bypasses_the_throttle(): void
    {
        config(['postsyncer.write_throttle.min_interval_seconds' => 0]);

        $throttle = $this->throttle();
        $throttle->run(fn (): bool => true);
        $throttle->run(fn (): bool => true);

        $this->assertSame([], $this->sleeps);
    }

    public function test_the_lock_is_released_when_the_write_throws(): void
    {
        $throttle = $this->throttle();

        try {
            $throttle->run(function (): void {
                throw new RuntimeException('write failed');
            });
            $this->fail('Expected the write exception to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('write failed', $exception->getMessage());
        }

        $this->assertTrue(Cache::lock(self::LOCK_KEY, 60)->get());
    }

    public function test_a_failed_lock_release_does_not_replace_the_write_result(): void
    {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('release')->once()->andThrow(new RuntimeException('cache down'));

        Cache::shouldReceive('lock')->once()->andReturn($lock);
        Cache::shouldReceive('get')->andReturnNull();
        Cache::shouldReceive('put')->andReturnTrue();

        $result = $this->throttle()->run(fn (): string => 'created');

        $this->assertSame('created', $result);
    }

    public function test_a_cache_failure_before_the_write_is_refused_as_not_sent(): void
    {
        Cache::shouldReceive('lock')->once()->andThrow(new RuntimeException('cache down'));

        $writes = 0;
        try {
            $this->throttle()->run(function () use (&$writes): void {
                $writes++;
            });
            $this->fail('Expected a cache failure to refuse the write.');
        } catch (PostsyncerException $exception) {
            $this->assertTrue($exception->notSent);
            $this->assertFalse($exception->outcomeUnknown);
            $this->assertFalse($exception->responseReceived);
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }

        $this->assertSame(0, $writes);
    }

    public function test_a_short_wait_for_synchronous_requests_never_exceeds_five_seconds(): void
    {
        $holder = Cache::lock(self::LOCK_KEY, 900);
        $this->assertTrue($holder->get());

        $throttle = $this->throttle();
        try {
            $throttle->withShortWait(fn () => $throttle->run(fn (): bool => true));
            $this->fail('Expected a busy slot to be refused quickly.');
        } catch (PostsyncerException $exception) {
            $this->assertTrue($exception->notSent);
        }

        $this->assertLessThanOrEqual(PostsyncerWriteThrottle::SHORT_WAIT_CEILING, array_sum($this->sleeps));

        $holder->release();
    }

    public function test_waits_stay_inside_the_queue_job_budget(): void
    {
        $holder = Cache::lock(self::LOCK_KEY, 900);
        $this->assertTrue($holder->get());

        // A 40-second job leaves 10 seconds after its safety margin.
        $throttle = $this->throttle();
        try {
            $throttle->withinJobBudget(40, fn () => $throttle->run(fn (): bool => true));
            $this->fail('Expected the job budget to stop the wait.');
        } catch (PostsyncerException $exception) {
            $this->assertTrue($exception->notSent);
        }

        $this->assertSame(10.0, array_sum($this->sleeps));

        $holder->release();
    }

    public function test_wait_ceiling_plus_margin_fits_inside_every_publish_job_timeout(): void
    {
        $budget = PostsyncerWriteThrottle::MAX_WAIT_CEILING + PostsyncerWriteThrottle::JOB_SAFETY_MARGIN;

        $this->assertLessThan(PublishVideoJob::TIMEOUT_SECONDS, $budget);
        $this->assertLessThan(PublishPostJob::TIMEOUT_SECONDS, $budget);
    }

    public function test_the_lock_outlives_one_legitimate_write(): void
    {
        $lockSeconds = (new ReflectionClassConstant(PostsyncerWriteThrottle::class, 'LOCK_SECONDS'))->getValue();

        $this->assertGreaterThan(PostsyncerClient::HTTP_TIMEOUT_SECONDS, $lockSeconds);
    }
}
