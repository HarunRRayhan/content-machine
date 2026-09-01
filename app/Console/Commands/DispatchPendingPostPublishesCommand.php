<?php

namespace App\Console\Commands;

use App\Jobs\PublishPostJob;
use App\Models\Post;
use Illuminate\Console\Command;

class DispatchPendingPostPublishesCommand extends Command
{
    protected $signature = 'postsyncer:dispatch-pending-publishes {--limit=100 : Maximum queued posts to enqueue}';

    protected $description = 'Enqueue PostSyncer publishes that were committed without a job';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dispatched = 0;
        $staleAt = now()->subSeconds(PublishPostJob::TIMEOUT_SECONDS);

        Post::query()
            ->where(function ($query) use ($staleAt): void {
                $query->where('publish_state', 'queued')
                    ->orWhere(function ($query) use ($staleAt): void {
                        $query->where('publish_state', 'running')
                            ->where(function ($query) use ($staleAt): void {
                                $query->whereNull('publish_claimed_at')
                                    ->orWhere('publish_claimed_at', '<=', $staleAt);
                            });
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Post $post) use (&$dispatched): void {
                $progress = $post->publish_progress;

                // Legacy queued rows have no progress snapshot. An empty
                // option set lets the worker plan them from current content;
                // the claim then creates a fresh durable snapshot.
                $options = is_array($progress) && is_array($progress['options'] ?? null)
                    ? $progress['options']
                    : [];

                $operationId = is_string($progress['operation_id'] ?? null)
                    ? $progress['operation_id']
                    : null;

                $leaseId = is_string($post->publish_lease_id) ? $post->publish_lease_id : null;

                PublishPostJob::dispatch($post, $options, $operationId, $leaseId);
                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} pending PostSyncer publish(es).");

        return self::SUCCESS;
    }
}
