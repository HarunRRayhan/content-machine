<?php

namespace App\Support\Postsyncer;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Serializes and spaces PostSyncer writes across every Content Machine process
 * that shares the application cache (web and worker).
 *
 * Timing invariants (enforced by PostsyncerWriteThrottleTest):
 * - The lock is held for one whole write, so LOCK_SECONDS must exceed the
 *   client's HTTP timeout. A lock leaked by a killed process clears by itself
 *   after LOCK_SECONDS.
 * - A write waits for its slot at most MAX_WAIT_CEILING seconds, and never past
 *   the job budget (job timeout minus JOB_SAFETY_MARGIN) inside a queue job.
 *   Waiting happens before a write is sent, but after publish progress is
 *   checkpointed, so a job killed mid-wait would leave an uncertain checkpoint.
 *   Keeping MAX_WAIT_CEILING + JOB_SAFETY_MARGIN below every publish job
 *   timeout prevents that.
 * - Synchronous web requests use SHORT_WAIT_CEILING so they never block for long.
 *
 * Failures of the throttle itself (cache errors, a busy slot) are raised as
 * notSent exceptions before any request is sent. A failure of the lock release
 * after a finished write is logged and does not replace the write's result.
 */
final class PostsyncerWriteThrottle
{
    public const MAX_WAIT_CEILING = 60;

    public const SHORT_WAIT_CEILING = 5;

    public const JOB_SAFETY_MARGIN = 30;

    private const LOCK_KEY = 'postsyncer:write-throttle:lock';

    private const LAST_START_KEY = 'postsyncer:write-throttle:last-start';

    // One write holds the lock, so this must exceed the client's HTTP timeout
    // (300 seconds) with a margin. A leaked lock then clears quickly.
    private const LOCK_SECONDS = PostsyncerClient::HTTP_TIMEOUT_SECONDS + 30;

    private const POLL_MICROSECONDS = 250_000;

    private ?float $budgetDeadline = null;

    private ?int $waitCeiling = null;

    /**
     * @param  (Closure(): float)|null  $clock
     * @param  (Closure(float): void)|null  $sleep  Receives seconds to wait.
     */
    public function __construct(
        private readonly ?Closure $clock = null,
        private readonly ?Closure $sleep = null,
    ) {}

    /**
     * Run a write once it holds the PostSyncer slot.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    public function run(Closure $write): mixed
    {
        $interval = (int) config('postsyncer.write_throttle.min_interval_seconds', 20);

        if (! (bool) config('postsyncer.write_throttle.enabled', true) || $interval <= 0) {
            return $write();
        }

        $now = $this->now();
        $waitUntil = $now + $this->effectiveWaitSeconds();

        if ($this->budgetDeadline !== null) {
            $waitUntil = min($waitUntil, $this->budgetDeadline);
        }

        $lock = $this->acquire($waitUntil);

        try {
            $this->reserveSlot($interval, $waitUntil);

            return $write();
        } finally {
            $this->releaseQuietly($lock);
        }
    }

    /**
     * Bound waits for synchronous web requests to a few seconds.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withShortWait(Closure $callback): mixed
    {
        $previous = $this->waitCeiling;
        $this->waitCeiling = self::SHORT_WAIT_CEILING;

        try {
            return $callback();
        } finally {
            $this->waitCeiling = $previous;
        }
    }

    /**
     * Keep every wait inside a queue job's timeout budget.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withinJobBudget(int $jobTimeoutSeconds, Closure $callback): mixed
    {
        $previous = $this->budgetDeadline;
        $this->budgetDeadline = $this->now() + max(0, $jobTimeoutSeconds - self::JOB_SAFETY_MARGIN);

        try {
            return $callback();
        } finally {
            $this->budgetDeadline = $previous;
        }
    }

    private function acquire(float $waitUntil): Lock
    {
        try {
            $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

            while (! $lock->get()) {
                if ($this->now() >= $waitUntil) {
                    throw $this->notSent('Another PostSyncer write is in progress.');
                }

                $this->pause(self::POLL_MICROSECONDS / 1_000_000);
            }

            return $lock;
        } catch (PostsyncerException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->notSent('The PostSyncer write throttle is unavailable.', $exception);
        }
    }

    private function reserveSlot(int $interval, float $waitUntil): void
    {
        try {
            $lastStart = Cache::get(self::LAST_START_KEY);

            if (is_numeric($lastStart)) {
                $slotAt = (float) $lastStart + $interval;
                $now = $this->now();

                if ($slotAt > $now) {
                    if ($slotAt > $waitUntil) {
                        throw $this->notSent('The PostSyncer write spacing wait exceeded its limit.');
                    }

                    $this->pause($slotAt - $now);
                }
            }

            Cache::put(self::LAST_START_KEY, $this->now(), 3600);
        } catch (PostsyncerException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->notSent('The PostSyncer write throttle is unavailable.', $exception);
        }
    }

    /**
     * A release failure must not hide the write's result. The lock expires on
     * its own after LOCK_SECONDS.
     */
    private function releaseQuietly(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $exception) {
            Log::warning('PostSyncer write throttle could not release its lock. It expires on its own.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function effectiveWaitSeconds(): float
    {
        $configured = max(0, (int) config('postsyncer.write_throttle.max_wait_seconds', 60));
        $ceiling = $this->waitCeiling ?? self::MAX_WAIT_CEILING;

        return (float) min($configured, $ceiling);
    }

    private function notSent(string $reason, ?Throwable $previous = null): PostsyncerException
    {
        return new PostsyncerException(
            'PostSyncer write was not sent: '.$reason.' It will be retried.',
            0,
            $previous,
            retryable: true,
            outcomeUnknown: false,
            responseReceived: false,
            safeToRetry: true,
            notSent: true,
        );
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    private function pause(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if ($this->sleep !== null) {
            ($this->sleep)($seconds);

            return;
        }

        usleep((int) round($seconds * 1_000_000));
    }
}
