<?php

namespace App\Actions\Postsyncer;

use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\TelegramPostRequest;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EnqueuePostPublishAction
{
    public function __construct(
        private readonly ?PublishPostAction $publishPostAction = null,
    ) {}

    /**
     * Queue a PostSyncer publish for a post. The worker runs PublishPostJob.
     *
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool, telegram_request_id?: int}  $options
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

        // Reload under a row lock so concurrent requests cannot both pass the
        // state checks and enqueue duplicate PostSyncer jobs.
        $queuedPost = DB::transaction(function () use ($post, $filtered, $config): Post {
            $lockedPost = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (($lockedPost->approval_state ?? 'approved') !== 'approved') {
                throw ValidationException::withMessages([
                    'publish' => __('This post needs human approval before it can be published.'),
                ]);
            }

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
            $isRetry = $lockedPost->publish_state === 'failed';

            if ($isRetry && is_array($progress)) {
                $requestedRequestId = $this->telegramRequestId($filtered);
                $storedRequestId = $this->telegramRequestId($progress['options'] ?? []);

                if ($requestedRequestId !== null && $requestedRequestId !== $storedRequestId) {
                    throw ValidationException::withMessages([
                        'publish' => __('This retry belongs to a different Telegram publish request.'),
                    ]);
                }

                $filtered = $this->resumeOptions($lockedPost);
            } else {
                try {
                    $plan = ($this->publishPostAction ?? app(PublishPostAction::class))
                        ->freezePlan($lockedPost, $config, $filtered);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        'when' => $exception->getMessage(),
                    ]);
                }
                $filtered = $plan['options'];
                $progress = $this->newProgress($plan);
            }

            $leaseId = (string) Str::uuid();

            $telegramRequest = $this->telegramRequest(
                $filtered,
                $lockedPost,
                $isRetry,
            );

            $lockedPost->forceFill([
                'publish_state' => 'queued',
                'publish_error' => null,
                'publish_progress' => $progress,
                'publish_claimed_at' => null,
                'publish_lease_id' => $leaseId,
            ])->save();

            if ($telegramRequest?->state === TelegramPostRequest::FAILED) {
                $telegramRequest->forceFill([
                    'state' => TelegramPostRequest::APPROVED,
                    'error_message' => null,
                ])->save();
            }

            // Do not let a worker observe the job before the publish
            // checkpoint and queued state commit.
            PublishPostJob::dispatch(
                $lockedPost,
                $filtered,
                is_string($progress['operation_id'] ?? null) ? $progress['operation_id'] : null,
                $leaseId,
            )->afterCommit();

            return $lockedPost;
        });

        return $queuedPost->fresh() ?? $queuedPost;
    }

    /**
     * A retry must use the original operation options. In particular, an
     * omitted `when` must not turn an interrupted schedule into publish-now.
     *
     * @return array<string, mixed>
     */
    private function resumeOptions(Post $post): array
    {
        if ($post->publish_state !== 'failed' || ! is_array($post->publish_progress)) {
            throw ValidationException::withMessages([
                'publish' => __('This post has an invalid PostSyncer retry state.'),
            ]);
        }

        $progress = $post->publish_progress;
        $current = $progress['current'] ?? null;
        $state = $progress['state'] ?? null;
        $unknownCurrent = $current !== null
            && (! is_array($current) || ($current['phase'] ?? null) !== 'uploading');

        if ($state === 'uncertain' || $unknownCurrent) {
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

        return $options;
    }

    /**
     * @param  array{hash: string, groups: list<array{index: int, group_key: string}>, options: array<string, mixed>}  $plan
     * @return array<string, mixed>
     */
    private function newProgress(array $plan): array
    {
        return [
            'version' => 1,
            'operation_id' => (string) Str::uuid(),
            'options' => $plan['options'],
            'plan_hash' => $plan['hash'],
            'planned_groups' => $plan['groups'],
            'completed_groups' => [],
            'current' => null,
            'state' => 'queued',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function telegramRequest(array $options, Post $post, bool $isRetry): ?TelegramPostRequest
    {
        $id = $options['telegram_request_id'] ?? null;

        if ($id === null) {
            if (! $isRetry) {
                return null;
            }

            $requests = $post->telegramPostRequests()
                ->where('state', TelegramPostRequest::FAILED)
                ->latest('id')
                ->get();

            if ($requests->count() > 1) {
                throw ValidationException::withMessages([
                    'publish' => __('Specify the Telegram post request to retry.'),
                ]);
            }

            return $requests->first();
        }

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            throw ValidationException::withMessages([
                'publish' => __('The Telegram publish request is invalid.'),
            ]);
        }

        $request = TelegramPostRequest::query()
            ->whereKey((int) $id)
            ->lockForUpdate()
            ->first();

        if ($request === null
            || $request->workspace_id !== $post->workspace_id
            || $request->post_id !== $post->id
            || ! in_array($request->state, [
                TelegramPostRequest::APPROVED,
                ...($isRetry ? [TelegramPostRequest::FAILED] : []),
            ], true)
        ) {
            throw ValidationException::withMessages([
                'publish' => __('This Telegram draft is no longer approved for publishing.'),
            ]);
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function telegramRequestId(array $options): ?int
    {
        $id = $options['telegram_request_id'] ?? null;

        if ($id === null) {
            return null;
        }

        if (is_int($id) && $id > 0) {
            return $id;
        }

        if (is_string($id) && ctype_digit($id) && (int) $id > 0) {
            return (int) $id;
        }

        throw ValidationException::withMessages([
            'publish' => __('The Telegram publish request is invalid.'),
        ]);
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

            $postId = $group['post_id'] ?? null;

            if (is_int($postId) || is_float($postId)) {
                if ($postId > 0) {
                    return true;
                }

                continue;
            }

            if (is_string($postId) && trim($postId) !== '') {
                return true;
            }
        }

        return false;
    }
}
