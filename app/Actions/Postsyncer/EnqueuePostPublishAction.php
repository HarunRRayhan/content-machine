<?php

namespace App\Actions\Postsyncer;

use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EnqueuePostPublishAction
{
    /**
     * Queue a PostSyncer publish for a post. The worker runs PublishPostJob.
     *
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options
     */
    public function handle(Post $post, Workspace $workspace, array $options = []): Post
    {
        abort_if($post->workspace_id !== $workspace->id, 404);

        $config = PostsyncerConfig::fromWorkspace($workspace);

        if (! $config->isReadyForPublish()) {
            throw ValidationException::withMessages([
                'publish' => __('PostSyncer is not configured for publishing.'),
            ]);
        }

        $filtered = array_filter($options, fn ($value) => $value !== null);

        $queued = DB::transaction(function () use ($post, $workspace, $filtered): Post {
            $this->assertAtomicQueueConfiguration();

            Workspace::query()
                ->whereKey($workspace->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPost = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($lockedPost->workspace_id !== $workspace->id, 404);

            if (in_array($lockedPost->publish_state, ['queued', 'running'], true)) {
                throw ValidationException::withMessages([
                    'publish' => __('A publish is already in progress.'),
                ]);
            }

            if ($this->alreadyPublishedOnPostsyncer($lockedPost)) {
                throw ValidationException::withMessages([
                    'publish' => __('This post already has PostSyncer posts. Republish is not supported yet.'),
                ]);
            }

            $progress = $lockedPost->publish_progress;
            $runToken = (string) Str::uuid();

            if ($progress !== null) {
                [$filtered, $progress] = $this->resumeOptions($lockedPost, $filtered);
            } else {
                $progress = $this->newProgress($filtered, $runToken);
            }

            // A retry is a new run even when it resumes the same operation.
            // This fences off an automatic queue retry still holding the old
            // job's serialized options.
            $progress['run_token'] = $runToken;
            $progress['state'] = 'queued';

            $lockedPost->forceFill([
                'publish_state' => 'queued',
                'publish_error' => null,
                'publish_progress' => $progress,
            ])->save();

            // The database queue uses the same connection as this transaction.
            // Insert the job atomically with the queued checkpoint so a queue
            // write failure rolls the record back instead of leaving it stuck.
            $this->dispatchJob($lockedPost, $filtered, $runToken);

            return $lockedPost;
        });

        return $queued->fresh() ?? $queued;
    }

    private function assertAtomicQueueConfiguration(): void
    {
        if (config('queue.connections.postsyncer.driver') !== 'database') {
            throw ValidationException::withMessages([
                'publish' => __('The PostSyncer queue must use the database driver for atomic publish enqueueing.'),
            ]);
        }

        $queueConnection = config('queue.connections.postsyncer.connection')
            ?: config('database.default');

        if ((string) $queueConnection !== (string) DB::getDefaultConnection()) {
            throw ValidationException::withMessages([
                'publish' => __('The PostSyncer queue must use the application database connection for atomic publish enqueueing.'),
            ]);
        }
    }

    /**
     * Release Laravel's unique lock if the database queue insert fails. The
     * lock is acquired by PendingDispatch before the job is inserted.
     *
     * @param  array<string, mixed>  $options
     */
    private function dispatchJob(Post $post, array $options, string $runToken): void
    {
        $job = new PublishPostJob($post, $options, $runToken);

        try {
            $pending = dispatch($job)->beforeCommit();
            unset($pending);
        } catch (Throwable $exception) {
            (new UniqueLock(app(CacheRepository::class)))->release($job);

            throw $exception;
        }
    }

    /**
     * A retry must use the original operation options. In particular, an
     * omitted `when` must not turn an interrupted schedule into publish-now.
     *
     * @param  array<string, mixed>  $requested
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function resumeOptions(Post $post, array $requested): array
    {
        if ($post->publish_state !== 'failed' || ! is_array($post->publish_progress)) {
            throw ValidationException::withMessages([
                'publish' => __('This post has an invalid PostSyncer retry state.'),
            ]);
        }

        $progress = $post->publish_progress;
        $current = $progress['current'] ?? null;
        $state = $progress['state'] ?? null;

        $currentPhase = is_array($current) ? ($current['phase'] ?? null) : null;
        if ($state === 'uncertain' || ($current !== null && $currentPhase !== 'retryable')) {
            throw ValidationException::withMessages([
                'publish' => __('A PostSyncer create has an unknown outcome. Reconcile it before retrying.'),
            ]);
        }

        if ($state !== 'failed') {
            throw ValidationException::withMessages([
                'publish' => __('This post cannot be resumed from its current PostSyncer state.'),
            ]);
        }

        $options = $progress['options'] ?? null;

        if (! is_array($options)) {
            throw ValidationException::withMessages([
                'publish' => __('The original PostSyncer publish options are missing.'),
            ]);
        }

        // Schedule and platform changes could target a different operation.
        // The confirmation gate is safe to change only before any external
        // progress has been checkpointed, which lets a failed ask-gated
        // preflight be approved from the retry dialog.
        if (array_key_exists('confirm_ask', $requested)
            && (bool) ($requested['confirm_ask'] ?? false)
                !== (bool) ($options['confirm_ask'] ?? false)) {
            if ($this->hasCompletedGroups($progress)
                || ($progress['current'] ?? null) !== null) {
                throw ValidationException::withMessages([
                    'publish' => __('Only the ask-platform confirmation may change when retrying a publish.'),
                ]);
            }

            $options['confirm_ask'] = (bool) $requested['confirm_ask'];
            $progress['options'] = $options;
            $progress['plan_hash'] = null;
            $progress['planned_groups'] = [];
        }

        // All other values are intentionally ignored on resume. This
        // preserves the original schedule even when a retry caller sends no
        // options at all.
        return [$options, $progress];
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function hasCompletedGroups(array $progress): bool
    {
        return is_array($progress['completed_groups'] ?? null)
            && $progress['completed_groups'] !== [];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function newProgress(array $options, string $runToken): array
    {
        return [
            'version' => 1,
            'operation_id' => (string) Str::uuid(),
            'run_token' => $runToken,
            'options' => $options,
            'plan_hash' => null,
            'planned_groups' => [],
            'completed_groups' => [],
            'current' => null,
            'state' => 'queued',
        ];
    }

    private function alreadyPublishedOnPostsyncer(Post $post): bool
    {
        $groups = $post->postsyncer['groups'] ?? null;

        if (! is_array($groups)) {
            return false;
        }

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            if ($this->hasPostId($group['post_id'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function hasPostId(mixed $postId): bool
    {
        if (is_int($postId) || is_float($postId)) {
            return $postId > 0;
        }

        return is_string($postId) && trim($postId) !== '';
    }
}
