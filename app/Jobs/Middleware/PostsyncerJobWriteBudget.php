<?php

namespace App\Jobs\Middleware;

use App\Support\Postsyncer\PostsyncerWriteThrottle;

/**
 * Keeps PostSyncer write waits inside the job's timeout so a job is never
 * killed while it waits for a write slot after checkpointing progress.
 */
class PostsyncerJobWriteBudget
{
    public function __construct(
        private readonly int $timeoutSeconds,
    ) {}

    public function handle(object $job, callable $next): void
    {
        app(PostsyncerWriteThrottle::class)->withinJobBudget(
            $this->timeoutSeconds,
            static fn () => $next($job),
        );
    }
}
