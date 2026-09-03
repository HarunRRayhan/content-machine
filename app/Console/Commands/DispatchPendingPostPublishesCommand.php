<?php

namespace App\Console\Commands;

use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DispatchPendingPostPublishesCommand extends Command
{
    protected $signature = 'postsyncer:dispatch-pending-publishes {--limit=100 : Maximum queued posts to enqueue}';

    protected $description = 'Enqueue PostSyncer publishes that were committed without a job';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dispatched = 0;

        /** @var list<array{id: int, post: Post, options: array<string, mixed>, operation_id: string|null, run_token: string|null, lease_id: string|null}> $claims */
        $claims = DB::transaction(function () use ($limit): array {
            $staleAt = now()->subSeconds(PublishPostJob::LEASE_SECONDS);
            $claims = [];

            // Publish and cancellation paths lock workspace before post. Keep
            // the same order while transferring a stale row to recovery.
            Workspace::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            Post::query()
                ->where(function ($query) use ($staleAt): void {
                    $query->where(function ($query) use ($staleAt): void {
                        $query->where('publish_state', 'queued')
                            ->where(function ($query) use ($staleAt): void {
                                $query->whereNull('publish_claimed_at')
                                    ->orWhere('publish_claimed_at', '<=', $staleAt);
                            });
                    })->orWhere(function ($query) use ($staleAt): void {
                        $query->where('publish_state', 'running')
                            ->where(function ($query) use ($staleAt): void {
                                $query->whereNull('publish_claimed_at')
                                    ->orWhere('publish_claimed_at', '<=', $staleAt);
                            });
                    });
                })
                ->orderBy('id')
                ->limit($limit)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get()
                ->each(function (Post $post) use (&$claims): void {
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

                    $runToken = is_string($progress['run_token'] ?? null)
                        ? $progress['run_token']
                        : null;

                    $leaseId = is_string($post->publish_lease_id)
                        ? $post->publish_lease_id
                        : null;
                    $claimExpired = $post->publish_claimed_at === null
                        || $post->publish_claimed_at->lessThanOrEqualTo(now()->subSeconds(PublishPostJob::LEASE_SECONDS));

                    // A stale running worker must be fenced before its recovery
                    // job is inserted. Queue the recovery under a fresh lease so
                    // the old worker's failed() callback cannot overwrite it.
                    // A queued row with a live lease may still have a valid job in
                    // the queue; leave that lease alone until its unique lock has
                    // had time to expire.
                    if ($post->publish_state === 'running'
                        || ($post->publish_state === 'queued'
                            && $post->publish_claimed_at !== null
                            && $claimExpired)) {
                        $leaseId = (string) Str::uuid();
                        $post->forceFill([
                            'publish_state' => 'queued',
                            'publish_claimed_at' => now(),
                            'publish_lease_id' => $leaseId,
                        ])->save();
                    }

                    $claims[] = [
                        'id' => $post->id,
                        'post' => $post,
                        'options' => $options,
                        'operation_id' => $operationId,
                        'run_token' => $runToken,
                        'lease_id' => $leaseId,
                    ];
                });

            return $claims;
        });

        foreach ($claims as $claim) {
            try {
                PublishPostJob::dispatch(
                    $claim['post'],
                    $claim['options'],
                    $claim['run_token'],
                    $claim['operation_id'],
                    $claim['lease_id'],
                );
                $dispatched++;
            } catch (Throwable $exception) {
                report($exception);

                if ($claim['lease_id'] !== null) {
                    // Keep the new lease, but make the row immediately eligible
                    // for the next scheduler tick. The old lease is already
                    // fenced and must never be restored.
                    Post::query()
                        ->whereKey($claim['id'])
                        ->where('publish_lease_id', $claim['lease_id'])
                        ->update([
                            'publish_claimed_at' => null,
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        $this->info("Dispatched {$dispatched} pending PostSyncer publish(es).");

        return self::SUCCESS;
    }
}
