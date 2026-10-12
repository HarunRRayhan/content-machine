<?php

namespace App\Jobs\Middleware;

use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PostsyncerWriteThrottle;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Keeps PostSyncer write waits inside the job's timeout, and separates
 * throttle waits from real failures.
 *
 * - A write that could not get its slot (notSent) releases the job with a
 *   jittered delay. A release does not spend one of the job's $tries.
 * - Real failures are counted per job and rethrown, so Laravel backs off as
 *   before. When the count reaches $tries, the job fails.
 * - The job's retryUntil() bounds releases to RETRY_UNTIL_SECONDS.
 */
class PostsyncerJobWriteBudget
{
    public const RETRY_UNTIL_SECONDS = 900;

    public const RELEASE_MIN_SECONDS = 30;

    public const RELEASE_MAX_SECONDS = 90;

    public function __construct(
        private readonly int $timeoutSeconds,
    ) {}

    public function handle(object $job, callable $next): void
    {
        try {
            app(PostsyncerWriteThrottle::class)->withinJobBudget(
                $this->timeoutSeconds,
                static fn () => $next($job),
            );
        } catch (PostsyncerException $exception) {
            if ($exception->notSent && $this->canRelease($job)) {
                $job->release(random_int(self::RELEASE_MIN_SECONDS, self::RELEASE_MAX_SECONDS));

                return;
            }

            if ($exception->notSent) {
                throw $exception;
            }

            if ($this->failIfAttemptsSpent($job, $exception)) {
                return;
            }

            throw $exception;
        }
    }

    private function canRelease(object $job): bool
    {
        return method_exists($job, 'release') && $this->queueJobId($job) !== null;
    }

    /**
     * Count real failures so they stop after $tries, even though the job has
     * a retryUntil() deadline. Returns true when the job was failed here.
     */
    private function failIfAttemptsSpent(object $job, Throwable $exception): bool
    {
        $id = $this->queueJobId($job);
        $tries = (int) ($job->tries ?? 0);

        if ($id === null || $tries <= 0 || ! method_exists($job, 'fail')) {
            return false;
        }

        $key = 'postsyncer:job-real-failures:'.$id;
        Cache::add($key, 0, now()->addDay());
        $count = (int) Cache::increment($key);

        if ($count < $tries) {
            return false;
        }

        $job->fail($exception);

        return true;
    }

    private function queueJobId(object $job): ?string
    {
        $queueJob = $job->job ?? null;

        return is_object($queueJob) && method_exists($queueJob, 'uuid')
            ? (string) $queueJob->uuid()
            : null;
    }
}
