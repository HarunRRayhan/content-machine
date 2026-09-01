<?php

namespace App\Actions\Postsyncer;

use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\TelegramPostRequest;
use App\Support\Postsyncer\PostPublishPlanner;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PublishGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PublishPostAction
{
    public function __construct(
        private readonly PostPublishPlanner $planner,
    ) {}

    /**
     * Freeze the local plan metadata before a queued job can be processed.
     * Workers still re-plan to refresh expiring signed media URLs, but the
     * persisted hash and group keys make any content/config drift fail closed.
     *
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool, telegram_request_id?: int}  $options
     * @return array{hash: string, groups: list<array{index: int, group_key: string}>, options: array<string, mixed>}
     */
    public function freezePlan(Post $post, PostsyncerConfig $config, array $options): array
    {
        $normalizedOptions = $this->normalizeOptions($options);
        $groups = $this->planner->plan($post, $config, $normalizedOptions);

        return $this->planMetadata($config, $groups, $normalizedOptions);
    }

    /**
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool, telegram_request_id?: int}  $options
     */
    public function handle(
        Post $post,
        array $options,
        ?string $operationId = null,
        ?string $leaseId = null,
    ): void {
        // Queue serialization normally reloads this model, but direct callers
        // can still hold a stale instance after an edit or approval reset.
        $post->refresh();
        $originalStatus = $post->status;
        $options = $this->normalizeOptions($options);
        $claimLeaseId = null;

        try {
            $claimed = $this->claimPost($post, $options, $operationId, $leaseId);

            if ($claimed === null) {
                return;
            }

            $post = $claimed;
            $originalStatus = $post->status;
            $claimLeaseId = $post->publish_lease_id;
            $post->loadMissing('workspace');
            $this->assertFirstPublish($post);
            $config = PostsyncerConfig::fromWorkspace($post->workspace);
            $client = new PostsyncerClient($config);
            $groups = $this->planner->plan($post, $config, $options);

            if ($groups === []) {
                throw new PostsyncerException(
                    'No PostSyncer publish groups could be planned for this post.'
                );
            }

            $plan = $this->planMetadata($config, $groups, $options);
            $progress = $this->prepareProgress($post, $post->publish_progress, $plan);
            $completedGroups = $this->completedGroups($progress);

            foreach ($groups as $index => $group) {
                $groupKey = $this->groupKey($config, $group);

                if ($this->completedGroup($completedGroups, $index, $groupKey) !== null) {
                    continue;
                }

                $createIdempotencyKey = $this->idempotencyKey(
                    (string) $progress['operation_id'],
                    $index,
                    $groupKey,
                );
                $mediaIdempotencyKey = $this->mediaIdempotencyKey(
                    (string) $progress['operation_id'],
                    $index,
                    $groupKey,
                );
                $mediaIds = [];

                // Persist the upload phase before calling PostSyncer. If the
                // worker dies during the upload, retrying the same
                // idempotency key is safe and the post create is never
                // attempted with missing media.
                $progress['state'] = 'running';
                $progress['current'] = [
                    'index' => $index,
                    'group_key' => $groupKey,
                    'phase' => $group->mediaUrls === [] ? 'creating' : 'uploading',
                    'idempotency_key' => $createIdempotencyKey,
                    'media_idempotency_key' => $mediaIdempotencyKey,
                    'media_ids' => [],
                ];

                if (! $this->checkpointCurrent($post, $progress, $claimLeaseId)) {
                    throw new PostsyncerException(
                        'The publish was cancelled or approval changed before the PostSyncer request.'
                    );
                }

                if ($group->mediaUrls !== []) {
                    $mediaIds = $client->uploadFromUrls(
                        $group->workspaceId,
                        $group->mediaUrls,
                        $mediaIdempotencyKey,
                    );

                    // PostSyncer can return 200 with an empty media list when a
                    // signed/Drive URL fails to fetch. Never continue as a
                    // text post when the planner expected images (P-57).
                    if ($mediaIds === []) {
                        throw new PostsyncerException(
                            'PostSyncer returned no media ids for '.implode(', ', $group->platforms)
                            .' after uploading '.count($group->mediaUrls).' url(s).'
                            .' Refusing to publish this group without images.'
                        );
                    }

                    $progress['current']['phase'] = 'creating';
                    $progress['current']['media_ids'] = $mediaIds;

                    if (! $this->checkpointCurrent($post, $progress, $claimLeaseId)) {
                        throw new PostsyncerException(
                            'The publish was cancelled or approval changed after the media upload.'
                        );
                    }
                }

                $body = $this->buildPostBody($config, $group, $mediaIds);

                $result = $client->createPost(
                    $body,
                    (string) $progress['current']['idempotency_key'],
                );
                $completedGroups[] = $this->formatProgressGroup(
                    $group,
                    $result,
                    $index,
                    $groupKey,
                );
                usort(
                    $completedGroups,
                    fn (array $left, array $right): int => ((int) $left['index']) <=> ((int) $right['index']),
                );

                // Checkpoint each external create before attempting another
                // group, so a later failure resumes without recreating it.
                $progress['completed_groups'] = $completedGroups;
                $progress['current'] = null;

                if (! $this->checkpointCompleted(
                    $post,
                    $progress,
                    $index,
                    $groupKey,
                    $claimLeaseId,
                )) {
                    throw new PostsyncerException(
                        'The publish lease changed after the PostSyncer create. The result will be reconciled by the active worker.'
                    );
                }
            }

            $publishedGroups = [];
            foreach ($groups as $index => $group) {
                $groupKey = $this->groupKey($config, $group);
                $completed = $this->completedGroup($completedGroups, $index, $groupKey);

                if ($completed === null) {
                    throw new PostsyncerException(
                        'PostSyncer publish progress is incomplete. Retry the publish.'
                    );
                }

                $publishedGroups[] = $this->publicGroup($completed);
            }

            $progress['state'] = 'succeeded';
            $progress['completed_groups'] = $completedGroups;
            $progress['current'] = null;

            if (! $this->finalizeSuccess($post, $publishedGroups, $progress, $groups, $options, $claimLeaseId)) {
                throw new PostsyncerException(
                    'The publish could not be finalized because its approval or cancellation state changed.'
                );
            }
        } catch (Throwable $e) {
            $failure = $this->recordFailure(
                $post,
                $originalStatus,
                $options,
                $operationId,
                $claimLeaseId,
                $e,
            );

            if ($failure === null) {
                // A later worker owns the row, or it already finalized the
                // operation. Never overwrite that state from this exception.
                return;
            }

            // Deterministic validation/auth/resource errors are recorded and
            // deliberately consumed. Unknown outcomes are retried by the
            // queue worker, but the checkpoint makes every retry stop before
            // another POST /posts call.
            if (! ($e instanceof PostsyncerException && ! $e->retryable)) {
                throw $e;
            }
        }
    }

    /**
     * Reconcile the current group after a create response was lost. The
     * operator supplies the PostSyncer id after verifying it in PostSyncer;
     * this method verifies the id belongs to the expected workspace and
     * payload before checkpointing it.
     */
    public function reconcile(Post $post, int|string $postsyncerPostId): void
    {
        if (! $this->hasExistingPostId($postsyncerPostId)) {
            throw new PostsyncerException('A PostSyncer post id is required for reconciliation.');
        }

        $post->refresh();
        $progress = $post->publish_progress;

        if (! is_array($progress)) {
            throw new PostsyncerException('This post has no PostSyncer progress to reconcile.');
        }

        $this->assertProgressShape($progress);

        if (($progress['state'] ?? null) !== 'uncertain'
            || ! is_array($progress['current'] ?? null)
            || ($progress['current']['phase'] ?? null) !== 'creating'
        ) {
            throw new PostsyncerException(
                'This post does not have an uncertain PostSyncer create to reconcile.'
            );
        }

        $current = $progress['current'];
        $post->loadMissing('workspace');
        $config = PostsyncerConfig::fromWorkspace($post->workspace);
        $options = $progress['options'];
        $groups = $this->planner->plan($post, $config, $options);
        $index = $current['index'];
        $group = $groups[$index] ?? null;

        if (! $group instanceof PublishGroup
            || $this->groupKey($config, $group) !== $current['group_key']
        ) {
            throw new PostsyncerException(
                'The PostSyncer reconciliation group no longer matches the publish plan.'
            );
        }

        $client = new PostsyncerClient($config);
        $remote = $this->normalizePostResponse($client->getPost($postsyncerPostId));
        $this->assertReconciledPost(
            $remote,
            $config,
            $group,
            $current['media_ids'],
            $postsyncerPostId,
        );

        DB::transaction(function () use (
            $post,
            $progress,
            $current,
            $remote,
            $index,
            $postsyncerPostId,
        ): void {
            $lockedPost = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $latestProgress = $lockedPost->publish_progress;

            if (! is_array($latestProgress)
                || ($latestProgress['operation_id'] ?? null) !== ($progress['operation_id'] ?? null)
                || ($latestProgress['state'] ?? null) !== 'uncertain'
                || ($latestProgress['current']['index'] ?? null) !== $current['index']
                || ($latestProgress['current']['group_key'] ?? null) !== $current['group_key']
            ) {
                throw new PostsyncerException(
                    'The PostSyncer progress changed while reconciliation was running.'
                );
            }

            $lockedPost->loadMissing('workspace');
            $latestConfig = PostsyncerConfig::fromWorkspace($lockedPost->workspace);
            $latestGroups = $this->planner->plan(
                $lockedPost,
                $latestConfig,
                $latestProgress['options'],
            );
            $latestGroup = $latestGroups[$current['index']] ?? null;

            if (! $latestGroup instanceof PublishGroup
                || $this->groupKey($latestConfig, $latestGroup) !== $current['group_key']
            ) {
                throw new PostsyncerException(
                    'The post or PostSyncer settings changed while reconciliation was running.'
                );
            }

            $this->assertReconciledPost(
                $remote,
                $latestConfig,
                $latestGroup,
                $current['media_ids'],
                $postsyncerPostId,
            );

            $completedGroups = $this->completedGroups($latestProgress);
            $completedGroups[] = $this->formatProgressGroup(
                $latestGroup,
                $remote,
                $index,
                $current['group_key'],
            );
            usort(
                $completedGroups,
                fn (array $left, array $right): int => ((int) $left['index']) <=> ((int) $right['index']),
            );

            $latestProgress['completed_groups'] = $completedGroups;
            $latestProgress['current'] = null;
            $latestProgress['state'] = 'failed';
            $lockedPost->forceFill([
                'publish_state' => 'failed',
                'publish_error' => 'PostSyncer post '.(string) $postsyncerPostId
                    .' reconciled. Retry to continue the publish.',
                'publish_progress' => $latestProgress,
            ])->save();
        });

        $post->refresh();
    }

    private function hasDefinitiveResponse(Throwable $exception): bool
    {
        return $exception instanceof PostsyncerException
            && $exception->responseReceived
            && ! $exception->outcomeUnknown;
    }

    /**
     * Record a failure while holding the same row lease used by publish
     * checkpoints. Returning null means a newer worker owns the operation.
     *
     * @param  array<string, mixed>  $options
     * @return array{error: string, unknown: bool}|null
     */
    private function recordFailure(
        Post $post,
        string $originalStatus,
        array $options,
        ?string $operationId,
        ?string $leaseId,
        Throwable $exception,
    ): ?array {
        // A failure before claimPost established a lease is not owned by this
        // worker. In particular, a legacy serialized job must not overwrite a
        // newer operation from its stale model/options snapshot.
        if ($leaseId === null) {
            return null;
        }

        return DB::transaction(function () use (
            $post,
            $originalStatus,
            $options,
            $operationId,
            $leaseId,
            $exception,
        ): ?array {
            $locked = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->publish_state === 'succeeded') {
                return null;
            }

            $progress = $locked->publish_progress;

            if ($operationId !== null
                && (! is_array($progress) || ($progress['operation_id'] ?? null) !== $operationId)
            ) {
                return null;
            }

            if ($locked->publish_lease_id !== $leaseId) {
                return null;
            }

            // A non-error response proves that the create did not happen. A
            // timeout or 5xx response does not, so preserve the current group
            // and require reconciliation instead of creating a duplicate.
            if ($this->hasDefinitiveResponse($exception) && is_array($progress)) {
                $progress['current'] = null;
            }

            $unknownOutcome = is_array($progress)
                && ($this->hasUnknownCurrent($progress) || ($progress['state'] ?? null) === 'uncertain');

            if ($progress !== null) {
                $progress['state'] = $unknownOutcome ? 'uncertain' : 'failed';
            }

            $error = $exception->getMessage();
            if ($unknownOutcome) {
                $error = 'PostSyncer create outcome is uncertain. Reconcile PostSyncer before retrying. '.$error;
            }

            $locked->forceFill([
                'status' => $originalStatus,
                'publish_state' => 'failed',
                'publish_error' => $error,
                'publish_progress' => $progress,
                'publish_claimed_at' => null,
                'publish_lease_id' => null,
            ])->save();
            $this->updateTelegramPostRequests($locked, TelegramPostRequest::FAILED, $error, $options);

            return ['error' => $error, 'unknown' => $unknownOutcome];
        });
    }

    /**
     * @param  array<string, mixed>  $remote
     * @param  list<int|string>  $mediaIds
     */
    private function assertReconciledPost(
        array $remote,
        PostsyncerConfig $config,
        PublishGroup $group,
        array $mediaIds,
        int|string $postsyncerPostId,
    ): void {
        if ((string) ($remote['id'] ?? '') !== (string) $postsyncerPostId) {
            throw new PostsyncerException('The supplied PostSyncer post id was not found.');
        }

        $remoteStatus = strtoupper((string) ($remote['status'] ?? ''));
        $allowedStatuses = $group->publishNow
            ? ['PUBLISHED']
            : ['SCHEDULED', 'PUBLISHED'];

        if (! in_array($remoteStatus, $allowedStatuses, true)) {
            throw new PostsyncerException(
                'The supplied PostSyncer post is not in a publishable terminal state.',
            );
        }

        $remoteWorkspaceId = $remote['workspace_id'] ?? data_get($remote, 'workspace.id');
        if ($remoteWorkspaceId === null
            || (string) $remoteWorkspaceId !== (string) $group->workspaceId
        ) {
            throw new PostsyncerException(
                'The supplied PostSyncer post belongs to a different workspace.'
            );
        }

        $expected = $this->buildPostBody($config, $group, $mediaIds);
        $remoteContent = $remote['content'] ?? null;

        if (! is_array($remoteContent) || count($remoteContent) !== count($expected['content'])) {
            throw new PostsyncerException(
                'The supplied PostSyncer post does not match the current publish group.'
            );
        }

        foreach ($expected['content'] as $index => $expectedItem) {
            $remoteItem = $remoteContent[$index] ?? null;

            if (! is_array($remoteItem)
                || ($remoteItem['text'] ?? null) !== ($expectedItem['text'] ?? null)
                || $this->responseMediaIds($remoteItem['media'] ?? [])
                    !== array_map('strval', $expectedItem['media'] ?? [])
            ) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post does not match the current publish group.'
                );
            }

            if ((bool) ($remoteItem['is_first_comment'] ?? false)
                !== (bool) ($expectedItem['is_first_comment'] ?? false)
                || (int) ($remoteItem['first_comment_delay'] ?? 0)
                    !== (int) ($expectedItem['first_comment_delay'] ?? 0)
            ) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post does not match the current publish group.'
                );
            }
        }

        $remotePlatforms = $remote['platforms'] ?? null;
        if (! is_array($remotePlatforms)) {
            throw new PostsyncerException(
                'The supplied PostSyncer post has no platform details to verify.'
            );
        }

        $actualPlatforms = [];
        foreach ($remotePlatforms as $platform) {
            if (is_array($platform) && is_string($platform['platform'] ?? null)) {
                $actualPlatforms[] = strtolower($platform['platform']);
            }
        }

        $expectedPlatforms = array_map('strtolower', $group->platforms);
        sort($actualPlatforms);
        sort($expectedPlatforms);

        if ($actualPlatforms !== $expectedPlatforms) {
            throw new PostsyncerException(
                'The supplied PostSyncer post does not match the current publish group.'
            );
        }

        if (! $group->publishNow) {
            $scheduledAt = $remote['scheduled_at'] ?? null;

            if (! is_string($scheduledAt) || trim($scheduledAt) === '' || $group->when === null) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post has no verifiable schedule.'
                );
            }

            try {
                $remoteWhen = CarbonImmutable::parse($scheduledAt, $group->when->timezone);
            } catch (Throwable) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post has an invalid schedule.'
                );
            }

            if ($remoteWhen->format('Y-m-d H:i') !== $group->when->format('Y-m-d H:i')) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post does not match the requested schedule.'
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function normalizePostResponse(array $response): array
    {
        return ! array_key_exists('id', $response) && is_array($response['data'] ?? null)
            ? $response['data']
            : $response;
    }

    /**
     * @return list<string>
     */
    private function responseMediaIds(mixed $media): array
    {
        if (! is_array($media)) {
            return [];
        }

        $ids = [];
        foreach ($media as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : $item;
            if ($this->hasExistingPostId($id)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>|null  $options
     */
    private function updateTelegramPostRequests(
        Post $post,
        string $state,
        ?string $errorMessage = null,
        ?array $options = null,
    ): void {
        $query = $post->telegramPostRequests()
            ->whereIn('state', [
                TelegramPostRequest::AWAITING_APPROVAL,
                TelegramPostRequest::APPROVED,
                TelegramPostRequest::FAILED,
            ]);

        $requestId = $options === null ? null : $this->telegramRequestId($options);

        if ($requestId !== null) {
            $query->whereKey($requestId);
        }

        $query->update([
            'state' => $state,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Claim a queued/idle/failed publish under a row lock. A duplicate job
     * sees `running` and exits before it can call PostSyncer.
     *
     * @param  array<string, mixed>  $options
     */
    private function claimPost(
        Post $post,
        array $options,
        ?string $operationId = null,
        ?string $leaseId = null,
    ): ?Post {
        return DB::transaction(function () use ($post, $options, $operationId, $leaseId): ?Post {
            $locked = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $progress = $locked->publish_progress;

            if ($operationId !== null
                && (! is_array($progress) || ($progress['operation_id'] ?? null) !== $operationId)
            ) {
                return null;
            }

            if ($leaseId !== null && $locked->publish_lease_id !== $leaseId) {
                $safeUploadRetry = $locked->publish_lease_id === null
                    && $locked->publish_state === 'failed'
                    && is_array($progress)
                    && is_array($progress['current'] ?? null)
                    && ($progress['current']['phase'] ?? null) === 'uploading';

                if (! $safeUploadRetry) {
                    return null;
                }
            }

            if ($locked->publish_state === 'succeeded') {
                return null;
            }

            if ($locked->publish_state === 'failed'
                && is_array($progress)
                && ($this->hasUnknownCurrent($progress) || ($progress['state'] ?? null) === 'uncertain')
            ) {
                return null;
            }

            if ($locked->publish_state === 'running') {
                $claimedAt = $locked->publish_claimed_at;
                // A worker is forcibly stopped at the job timeout. The queue
                // lease is longer than that, so a redelivery after a crash is
                // safe to reclaim before the overlap lock expires.
                $staleAt = now()->subSeconds(PublishPostJob::TIMEOUT_SECONDS);

                if ($claimedAt !== null && $claimedAt->greaterThan($staleAt)) {
                    return null;
                }

                $unknownOutcome = is_array($progress)
                    && ($this->hasUnknownCurrent($progress) || ($progress['state'] ?? null) === 'uncertain');

                if ($unknownOutcome) {
                    $progress['state'] = 'uncertain';

                    $error = 'PostSyncer create outcome is uncertain. Reconcile PostSyncer before retrying. The previous publish worker lease expired.';
                    $locked->forceFill([
                        'publish_state' => 'failed',
                        'publish_error' => $error,
                        'publish_progress' => $progress,
                        'publish_claimed_at' => null,
                        'publish_lease_id' => null,
                    ])->save();
                    $this->updateTelegramPostRequests($locked, TelegramPostRequest::FAILED, $error, $options);

                    return null;
                }
            }

            $telegramRequest = $this->lockTelegramRequest($locked, $options);

            if ($telegramRequest !== null
                && in_array($telegramRequest->state, [
                    TelegramPostRequest::CANCELLED,
                    TelegramPostRequest::PUBLISHED,
                ], true)
            ) {
                if ($locked->publish_state === 'queued') {
                    $locked->forceFill([
                        'publish_state' => 'idle',
                        'publish_error' => null,
                        'publish_claimed_at' => null,
                        'publish_lease_id' => null,
                    ])->save();
                }

                return null;
            }

            if (($locked->approval_state ?? 'approved') !== 'approved') {
                $locked->forceFill([
                    'publish_state' => 'failed',
                    'publish_error' => 'This post needs human approval before it can be published.',
                    'publish_claimed_at' => null,
                    'publish_lease_id' => null,
                ])->save();

                return null;
            }

            if ($telegramRequest?->state === TelegramPostRequest::FAILED) {
                $telegramRequest->forceFill([
                    'state' => TelegramPostRequest::APPROVED,
                    'error_message' => null,
                ])->save();
            }

            $newLeaseId = $locked->publish_state === 'running'
                ? (string) Str::uuid()
                : ($locked->publish_lease_id ?? (string) Str::uuid());

            $locked->forceFill([
                'publish_state' => 'running',
                'publish_error' => null,
                'publish_claimed_at' => now(),
                'publish_lease_id' => $newLeaseId,
            ])->save();

            return $locked;
        });
    }

    /**
     * Lock the Telegram request associated with this operation. The request
     * is optional for dashboard/API publishes, but when present it is the
     * cancellation and approval boundary for the publish job.
     *
     * @param  array<string, mixed>  $options
     */
    private function lockTelegramRequest(Post $post, array $options): ?TelegramPostRequest
    {
        $requestId = $this->telegramRequestId($options);

        if ($requestId === null) {
            return null;
        }

        $request = TelegramPostRequest::query()
            ->whereKey($requestId)
            ->lockForUpdate()
            ->first();

        if ($request === null
            || $request->workspace_id !== $post->workspace_id
            || $request->post_id !== $post->id
        ) {
            throw new PostsyncerException('The Telegram publish request does not belong to this post.');
        }

        if (! in_array($request->state, [
            TelegramPostRequest::APPROVED,
            TelegramPostRequest::FAILED,
            TelegramPostRequest::CANCELLED,
            TelegramPostRequest::PUBLISHED,
        ], true)) {
            throw new PostsyncerException('The Telegram draft is not ready to publish.');
        }

        return $request;
    }

    /**
     * Persist the pre-create checkpoint only while approval and Telegram
     * cancellation still permit the operation.
     *
     * @param  array<string, mixed>  $progress
     */
    private function checkpointCurrent(Post $post, array $progress, ?string $leaseId): bool
    {
        return DB::transaction(function () use ($post, $progress, $leaseId): bool {
            $locked = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null
                || $locked->publish_state !== 'running'
                || ($locked->approval_state ?? 'approved') !== 'approved'
                || ! is_array($locked->publish_progress)
                || ($locked->publish_progress['operation_id'] ?? null) !== ($progress['operation_id'] ?? null)
                || ($leaseId !== null && $locked->publish_lease_id !== $leaseId)
            ) {
                return false;
            }

            $request = $this->lockTelegramRequest($locked, $progress['options'] ?? []);
            if ($request !== null
                && $request->state !== TelegramPostRequest::APPROVED
            ) {
                return false;
            }

            $locked->forceFill(['publish_progress' => $progress])->save();

            return true;
        });
    }

    /**
     * Persist a successful external create only while this worker still owns
     * the operation. A stale worker must not erase a newer worker's current
     * checkpoint after the queue visibility timeout expires.
     *
     * @param  array<string, mixed>  $progress
     */
    private function checkpointCompleted(
        Post $post,
        array $progress,
        int $index,
        string $groupKey,
        ?string $leaseId,
    ): bool {
        return DB::transaction(function () use ($post, $progress, $index, $groupKey, $leaseId): bool {
            $locked = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->first();

            $latestProgress = $locked?->publish_progress;
            $latestCurrent = is_array($latestProgress) ? ($latestProgress['current'] ?? null) : null;

            if ($locked === null
                || $locked->publish_state !== 'running'
                || ($locked->approval_state ?? 'approved') !== 'approved'
                || ($leaseId !== null && $locked->publish_lease_id !== $leaseId)
                || ! is_array($latestProgress)
                || ($latestProgress['operation_id'] ?? null) !== ($progress['operation_id'] ?? null)
                || ! is_array($latestCurrent)
                || ($latestCurrent['index'] ?? null) !== $index
                || ($latestCurrent['group_key'] ?? null) !== $groupKey
            ) {
                return false;
            }

            $request = $this->lockTelegramRequest($locked, $progress['options'] ?? []);
            if ($request !== null && $request->state !== TelegramPostRequest::APPROVED) {
                return false;
            }

            $locked->forceFill(['publish_progress' => $progress])->save();

            return true;
        });
    }

    /**
     * Finalize the public post record under the same lock used by edits and
     * cancellation. Public groups are written only after every planned group
     * has a durable external id.
     *
     * @param  list<array<string, mixed>>  $publishedGroups
     * @param  array<string, mixed>  $progress
     * @param  list<PublishGroup>  $groups
     * @param  array<string, mixed>  $options
     */
    private function finalizeSuccess(
        Post $post,
        array $publishedGroups,
        array $progress,
        array $groups,
        array $options,
        ?string $leaseId,
    ): bool {
        return DB::transaction(function () use ($post, $publishedGroups, $progress, $groups, $options, $leaseId): bool {
            $locked = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null
                || $locked->publish_state !== 'running'
                || ($locked->approval_state ?? 'approved') !== 'approved'
                || ! is_array($locked->publish_progress)
                || ($locked->publish_progress['operation_id'] ?? null) !== ($progress['operation_id'] ?? null)
                || ($leaseId !== null && $locked->publish_lease_id !== $leaseId)
            ) {
                return false;
            }

            $request = $this->lockTelegramRequest($locked, $options);
            if ($request !== null && $request->state !== TelegramPostRequest::APPROVED) {
                return false;
            }

            $locked->forceFill([
                'postsyncer' => ['groups' => $publishedGroups],
                'status' => $this->hasScheduledGroup($groups) ? 'scheduled' : 'posted',
                'publish_state' => 'succeeded',
                'publish_error' => null,
                'publish_progress' => $progress,
                'publish_claimed_at' => null,
                'publish_lease_id' => null,
            ])->save();

            $this->updateTelegramPostRequests($locked, TelegramPostRequest::PUBLISHED, null, $options);

            return true;
        });
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

        throw new PostsyncerException('The Telegram publish request id is invalid.');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function normalizeOptions(array $options): array
    {
        $normalized = [
            'when' => array_key_exists('when', $options) && $options['when'] !== null
                ? (string) $options['when']
                : null,
            'confirm_ask' => (bool) ($options['confirm_ask'] ?? false),
        ];

        if (array_key_exists('platforms', $options)) {
            $platforms = is_array($options['platforms'])
                ? array_values(array_map(
                    fn (mixed $platform): string => strtolower((string) $platform),
                    $options['platforms'],
                ))
                : [];
            sort($platforms);
            $normalized['platforms'] = $platforms;
        }

        $requestId = $this->telegramRequestId($options);
        if ($requestId !== null) {
            $normalized['telegram_request_id'] = $requestId;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  list<PublishGroup>  $groups
     * @param  array<string, mixed>  $options
     * @return array{hash: string, groups: list<array{index: int, group_key: string}>, options: array<string, mixed>}
     */
    private function planMetadata(PostsyncerConfig $config, array $groups, array $options): array
    {
        $plannedGroups = [];

        foreach ($groups as $index => $group) {
            $plannedGroups[] = [
                'index' => $index,
                'group_key' => $this->groupKey($config, $group),
            ];
        }

        $normalizedOptions = $this->normalizeOptions($options);
        $hash = hash('sha256', $this->canonicalJson([
            'options' => $normalizedOptions,
            'groups' => $plannedGroups,
        ]));

        return [
            'hash' => $hash,
            'groups' => $plannedGroups,
            'options' => $normalizedOptions,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array{hash: string, groups: list<array{index: int, group_key: string}>, options: array<string, mixed>}  $plan
     * @return array<string, mixed>
     */
    private function prepareProgress(Post $post, ?array $existing, array $plan): array
    {
        if ($existing === null) {
            $progress = [
                'version' => 1,
                'operation_id' => (string) Str::uuid(),
                'options' => $plan['options'],
                'plan_hash' => $plan['hash'],
                'planned_groups' => $plan['groups'],
                'completed_groups' => [],
                'current' => null,
                'state' => 'running',
            ];

            $post->forceFill(['publish_progress' => $progress])->save();

            return $progress;
        }

        $this->assertProgressShape($existing);

        if ($this->hasUnknownCurrent($existing)
            || ($existing['state'] ?? null) === 'uncertain'
        ) {
            throw new PostsyncerException(
                'A PostSyncer create has an unknown outcome. Reconcile it before retrying.'
            );
        }

        $storedOptions = $existing['options'] ?? null;
        if (! is_array($storedOptions)
            || $this->canonicalJson($this->normalizeOptions($storedOptions))
                !== $this->canonicalJson($plan['options'])
        ) {
            throw new PostsyncerException(
                'The publish options changed since this operation started. Reconcile the existing PostSyncer posts before retrying.'
            );
        }

        $storedHash = $existing['plan_hash'] ?? null;
        $storedGroups = $existing['planned_groups'] ?? null;

        if ($storedHash === null) {
            if ($storedGroups !== [] || $this->completedGroups($existing) !== []) {
                throw new PostsyncerException(
                    'PostSyncer publish progress has no plan metadata. Reconcile it before retrying.'
                );
            }

            $existing['plan_hash'] = $plan['hash'];
            $existing['planned_groups'] = $plan['groups'];
        } elseif (! is_string($storedHash)
            || $storedHash !== $plan['hash']
            || $storedGroups !== $plan['groups']
        ) {
            throw new PostsyncerException(
                'The publish plan changed since this operation started. Reconcile the existing PostSyncer posts before retrying.'
            );
        }

        $this->assertCompletedGroupsBelongToPlan($existing, $plan['groups']);

        $existing['options'] = $plan['options'];
        $existing['state'] = 'running';
        $existing['current'] = null;
        $post->forceFill(['publish_progress' => $existing])->save();

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function assertProgressShape(array $progress): void
    {
        $state = $progress['state'] ?? null;
        $plannedGroups = $progress['planned_groups'] ?? null;
        $completedGroups = $progress['completed_groups'] ?? null;

        if (($progress['version'] ?? null) !== 1
            || ! is_string($progress['operation_id'] ?? null)
            || trim($progress['operation_id']) === ''
            || ! in_array($state, ['queued', 'running', 'failed', 'succeeded', 'uncertain'], true)
            || ! is_array($progress['options'] ?? null)
            || ! is_array($plannedGroups)
            || ! is_array($completedGroups)
        ) {
            throw new PostsyncerException(
                'PostSyncer publish progress is invalid. Reconcile the post before retrying.'
            );
        }

        foreach ($plannedGroups as $planned) {
            if (! is_array($planned)
                || ! is_int($planned['index'] ?? null)
                || ! is_string($planned['group_key'] ?? null)
                || trim($planned['group_key']) === ''
            ) {
                throw new PostsyncerException(
                    'PostSyncer publish progress is invalid. Reconcile the post before retrying.'
                );
            }
        }

        foreach ($completedGroups as $completed) {
            if (! is_array($completed)
                || ! is_int($completed['index'] ?? null)
                || ! is_string($completed['group_key'] ?? null)
                || ! $this->hasExistingPostId($completed['post_id'] ?? null)
            ) {
                throw new PostsyncerException(
                    'PostSyncer publish progress is invalid. Reconcile the post before retrying.'
                );
            }
        }

        $current = $progress['current'] ?? null;
        $currentPhase = is_array($current) && array_key_exists('phase', $current)
            ? $current['phase']
            : null;
        $currentMediaIdempotencyKey = is_array($current) && array_key_exists('media_idempotency_key', $current)
            ? $current['media_idempotency_key']
            : null;
        if ($current !== null
            && (! is_array($current)
                || ! is_int($current['index'] ?? null)
                || ! is_string($current['group_key'] ?? null)
                || ! is_string($current['idempotency_key'] ?? null)
                || trim($current['idempotency_key']) === ''
                || ! in_array($currentPhase, ['uploading', 'creating'], true)
                || ($currentPhase === 'uploading'
                    && (! is_string($currentMediaIdempotencyKey)
                        || trim($currentMediaIdempotencyKey) === ''))
                || ! is_array($current['media_ids'] ?? null)
            )
        ) {
            throw new PostsyncerException(
                'PostSyncer publish progress is invalid. Reconcile the post before retrying.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return list<array<string, mixed>>
     */
    private function completedGroups(array $progress): array
    {
        $groups = $progress['completed_groups'] ?? [];

        return is_array($groups)
            ? array_values(array_filter($groups, static fn (mixed $group): bool => is_array($group)))
            : [];
    }

    /**
     * @param  array<string, mixed>  $progress
     * @param  list<array{index: int, group_key: string}>  $plannedGroups
     */
    private function assertCompletedGroupsBelongToPlan(array $progress, array $plannedGroups): void
    {
        $seen = [];

        foreach ($this->completedGroups($progress) as $completed) {
            $index = $completed['index'] ?? null;
            $key = $completed['group_key'] ?? null;
            $planned = is_int($index) ? ($plannedGroups[$index] ?? null) : null;

            if (! is_int($index)
                || ! is_string($key)
                || isset($seen[$index])
                || ! is_array($planned)
                || $planned['group_key'] !== $key
            ) {
                throw new PostsyncerException(
                    'Existing PostSyncer progress does not match this publish plan. Reconcile it before retrying.'
                );
            }

            $seen[$index] = true;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, mixed>|null
     */
    private function completedGroup(array $groups, int $index, string $groupKey): ?array
    {
        foreach ($groups as $group) {
            if (($group['index'] ?? null) === $index
                && ($group['group_key'] ?? null) === $groupKey
            ) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @param  list<PublishGroup>  $groups
     */
    private function hasScheduledGroup(array $groups): bool
    {
        foreach ($groups as $group) {
            if (! $group->publishNow) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalJson(array $value): string
    {
        return json_encode($this->canonicalValue($value), JSON_THROW_ON_ERROR);
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalValue($item),
                $value,
            );
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[(string) $key] = $this->canonicalValue($item);
        }
        ksort($canonical);

        return $canonical;
    }

    /**
     * A current group means the external create may have happened without a
     * response being persisted. Replaying it would be unsafe.
     *
     * @param  array<string, mixed>  $progress
     */
    private function hasUnknownCurrent(array $progress): bool
    {
        $current = $progress['current'] ?? null;

        if ($current === null) {
            return false;
        }

        // Media upload retries reuse the stable media idempotency key. A
        // create-phase checkpoint is different: the remote post may already
        // exist and must be reconciled before any retry.
        return ! is_array($current) || ($current['phase'] ?? null) !== 'uploading';
    }

    private function idempotencyKey(string $operationId, int $index, string $groupKey): string
    {
        return hash('sha256', $operationId.'|'.$index.'|'.$groupKey);
    }

    private function mediaIdempotencyKey(string $operationId, int $index, string $groupKey): string
    {
        return hash('sha256', 'media|'.$operationId.'|'.$index.'|'.$groupKey);
    }

    private function groupKey(PostsyncerConfig $config, PublishGroup $group): string
    {
        $langConfig = $config->language($group->language);
        $platformAccounts = $langConfig['platforms'];
        $accounts = [];
        $platforms = $group->platforms;
        sort($platforms);

        foreach ($platforms as $platform) {
            $platformConfig = is_array($platformAccounts[$platform] ?? null)
                ? $platformAccounts[$platform]
                : [];
            $accounts[$platform] = $platformConfig['account_id'] ?? null;
        }

        ksort($accounts);

        return hash('sha256', $this->canonicalJson([
            'language' => $group->language,
            'workspace_id' => (string) $group->workspaceId,
            'platforms' => $platforms,
            'accounts' => $accounts,
            'media_urls' => array_map(
                fn (string $url): string => $this->stableMediaUrl($url),
                $group->mediaUrls,
            ),
            'captions' => $group->captions,
            'thread_tweets' => $group->threadTweets,
            'first_comment' => $group->firstComment,
            'when' => $group->when?->toIso8601String(),
            'publish_now' => $group->publishNow,
        ]));
    }

    private function stableMediaUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return $url;
        }

        $query = [];
        if (is_string($parts['query'] ?? null)) {
            parse_str($parts['query'], $query);
        }

        $transientKeys = [
            'expires',
            'signature',
            'x-amz-algorithm',
            'x-amz-credential',
            'x-amz-date',
            'x-amz-expires',
            'x-amz-signedheaders',
            'x-amz-signature',
            'x-amz-security-token',
        ];

        foreach (array_keys($query) as $key) {
            if (in_array(strtolower((string) $key), $transientKeys, true)) {
                unset($query[$key]);
            }
        }

        ksort($query);

        $canonical = '';
        if (isset($parts['scheme'])) {
            $canonical .= $parts['scheme'].'://';
        }
        if (isset($parts['host'])) {
            $canonical .= $parts['host'];
        }
        if (isset($parts['port'])) {
            $canonical .= ':'.$parts['port'];
        }
        $canonical .= $parts['path'] ?? '';
        if ($query !== []) {
            $canonical .= '?'.http_build_query($query);
        }

        return $canonical !== '' ? $canonical : $url;
    }

    /**
     * @param  list<int|string>  $mediaIds
     * @return array<string, mixed>
     */
    private function buildPostBody(PostsyncerConfig $config, PublishGroup $group, array $mediaIds): array
    {
        $langConfig = $config->language($group->language);
        $platformAccounts = $langConfig['platforms'];

        $threadTweets = $group->threadTweets;
        $isThread = is_array($threadTweets) && $threadTweets !== [];

        if ($isThread) {
            $contentItems = [];
            foreach ($threadTweets as $index => $tweet) {
                $item = ['text' => $tweet];
                if (array_key_exists($index, $mediaIds)) {
                    $item['media'] = [$mediaIds[$index]];
                }
                $contentItems[] = $item;
            }
        } else {
            $contentItems = [[
                'text' => $this->defaultCaption($group),
                'media' => $mediaIds,
            ]];

            // Facebook/Instagram/LinkedIn/YouTube: second content item becomes
            // the delayed first comment. Never emit this on Threads/Twitter
            // groups (P-57: CM published captions but never the comment).
            if ($this->shouldAttachFirstComment($group)) {
                $contentItems[] = [
                    'text' => $group->firstComment,
                    'is_first_comment' => true,
                    'first_comment_delay' => 1,
                ];
            }
        }

        $body = [
            'workspace_id' => $group->workspaceId,
            'content' => $contentItems,
            'accounts' => $this->buildAccounts($platformAccounts, $group, $isThread),
            'schedule_type' => $group->publishNow ? 'publish_now' : 'schedule',
        ];

        if (! $group->publishNow && $group->when !== null) {
            $body['schedule_for'] = [
                'date' => $group->when->format('Y-m-d'),
                'time' => $group->when->format('H:i'),
                'timezone' => $group->when->timezoneName,
            ];
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $platformAccounts
     * @return list<array{id: int|string, settings: array<string, mixed>}>
     */
    private function buildAccounts(array $platformAccounts, PublishGroup $group, bool $isThread): array
    {
        $accounts = [];
        $hasMedia = $group->mediaUrls !== [];

        foreach ($group->platforms as $platform) {
            $platformConfig = is_array($platformAccounts[$platform] ?? null)
                ? $platformAccounts[$platform]
                : [];
            $accountId = $platformConfig['account_id'] ?? null;

            if ($accountId === null || $accountId === '') {
                throw new PostsyncerException("No account id mapped for platform {$platform}.");
            }

            $caption = $group->captions[$platform] ?? '';
            $settings = $this->platformSettings($platform, $caption, $isThread, $hasMedia);

            $accounts[] = [
                'id' => $accountId,
                'settings' => $settings,
            ];
        }

        return $accounts;
    }

    /**
     * @return array<string, mixed>
     */
    private function platformSettings(string $platform, string $caption, bool $isThread, bool $hasMedia): array
    {
        $settings = [];

        if ($caption === '') {
            return $settings;
        }

        return match ($platform) {
            'facebook', 'instagram' => array_filter([
                'post_type' => $hasMedia ? 'POST' : 'POST',
                'caption' => $caption,
            ]),
            'twitter' => $isThread ? [] : ['text' => $caption],
            'threads', 'bluesky' => $isThread ? [] : ['title' => $caption],
            'tiktok' => ['description' => $caption],
            default => [],
        };
    }

    private function defaultCaption(PublishGroup $group): string
    {
        if (isset($group->captions['facebook'])) {
            return $group->captions['facebook'];
        }

        $captions = $group->captions;
        $caption = reset($captions);

        return is_string($caption) ? $caption : '';
    }

    private function shouldAttachFirstComment(PublishGroup $group): bool
    {
        $comment = $group->firstComment;

        if (! is_string($comment) || trim($comment) === '') {
            return false;
        }

        foreach ($group->platforms as $platform) {
            if (! PublishGroup::supportsFirstComment($platform)) {
                return false;
            }
        }

        return true;
    }

    private function assertFirstPublish(Post $post): void
    {
        if ($this->hasExistingPublicGroup($post)) {
            throw new PostsyncerException(
                'This post already has PostSyncer posts. Republish is not supported yet.'
            );
        }
    }

    private function hasExistingPublicGroup(Post $post): bool
    {
        $groups = $post->postsyncer['groups'] ?? null;

        if (! is_array($groups)) {
            return false;
        }

        foreach ($groups as $group) {
            if (is_array($group) && $this->hasExistingPostId($group['post_id'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function hasExistingPostId(mixed $postId): bool
    {
        if (is_int($postId) || is_float($postId)) {
            return $postId > 0;
        }

        return is_string($postId) && trim($postId) !== '';
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{index: int, group_key: string, post_id: string, status: string, scheduled_at: string|null, platforms: list<string>, language: string}
     */
    private function formatProgressGroup(
        PublishGroup $group,
        array $result,
        int $index,
        string $groupKey,
    ): array {
        $postId = $result['id'] ?? null;

        if (! $this->hasExistingPostId($postId)) {
            throw new PostsyncerException('PostSyncer returned no post id after creating a group.');
        }

        return [
            'index' => $index,
            'group_key' => $groupKey,
            'post_id' => (string) $postId,
            'status' => strtoupper((string) ($result['status'] ?? '')),
            'scheduled_at' => isset($result['scheduled_at']) ? (string) $result['scheduled_at'] : null,
            'platforms' => $group->platforms,
            'language' => $group->language,
        ];
    }

    /**
     * @param  array<string, mixed>  $progressGroup
     * @return array{post_id: string, status: string, scheduled_at: string|null, platforms: list<string>, language: string}
     */
    private function publicGroup(array $progressGroup): array
    {
        return [
            'post_id' => (string) $progressGroup['post_id'],
            'status' => (string) $progressGroup['status'],
            'scheduled_at' => isset($progressGroup['scheduled_at'])
                ? (string) $progressGroup['scheduled_at']
                : null,
            'platforms' => is_array($progressGroup['platforms'] ?? null)
                ? array_values(array_map('strval', $progressGroup['platforms']))
                : [],
            'language' => (string) $progressGroup['language'],
        ];
    }
}
