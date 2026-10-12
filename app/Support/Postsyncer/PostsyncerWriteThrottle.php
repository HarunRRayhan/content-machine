<?php

namespace App\Support\Postsyncer;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Serializes and spaces PostSyncer writes across every Content Machine process
 * that shares the application cache (web and worker). The lock is held for the
 * whole write, so two writes never overlap, and the start of each write is at
 * least the configured interval after the previous start.
 *
 * A write that cannot get its slot within the bounded wait throws before any
 * request is sent. That exception is marked notSent, so publish progress is not
 * recorded as an unknown outcome.
 */
final class PostsyncerWriteThrottle
{
    private const LOCK_KEY = 'postsyncer:write-throttle:lock';

    private const LAST_START_KEY = 'postsyncer:write-throttle:last-start';

    // Longer than the client's 300-second HTTP timeout, so a held lock cannot expire mid-write.
    private const LOCK_SECONDS = 900;

    private const POLL_MICROSECONDS = 250_000;

    /**
     * @param  (Closure(): float)|null  $clock
     * @param  (Closure(float): void)|null  $sleep  Receives seconds to wait.
     */
    public function __construct(
        private readonly ?Closure $clock = null,
        private readonly ?Closure $sleep = null,
    ) {}

    /**
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

        $deadline = $this->now() + max(0, (int) config('postsyncer.write_throttle.max_wait_seconds', 240));
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        while (! $lock->get()) {
            if ($this->now() >= $deadline) {
                throw $this->notSent('Another PostSyncer write is in progress.');
            }

            $this->pause(self::POLL_MICROSECONDS / 1_000_000);
        }

        try {
            $lastStart = Cache::get(self::LAST_START_KEY);

            if (is_numeric($lastStart)) {
                $slotAt = (float) $lastStart + $interval;
                $now = $this->now();

                if ($slotAt > $now) {
                    if ($slotAt > $deadline) {
                        throw $this->notSent('PostSyncer write spacing wait exceeded the bounded limit.');
                    }

                    $this->pause($slotAt - $now);
                }
            }

            Cache::put(self::LAST_START_KEY, $this->now(), 3600);

            return $write();
        } finally {
            $lock->release();
        }
    }

    private function notSent(string $reason): PostsyncerException
    {
        return new PostsyncerException(
            'PostSyncer write was not sent: '.$reason.' It will be retried.',
            0,
            null,
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
