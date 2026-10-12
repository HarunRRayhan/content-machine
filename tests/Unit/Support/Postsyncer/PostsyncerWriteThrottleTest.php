<?php

namespace Tests\Unit\Support\Postsyncer;

use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PostsyncerWriteThrottle;
use Illuminate\Support\Facades\Cache;
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
            'postsyncer.write_throttle.max_wait_seconds' => 240,
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
        $this->assertGreaterThanOrEqual(240, array_sum($this->sleeps));

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
}
