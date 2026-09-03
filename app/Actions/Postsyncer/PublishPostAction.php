<?php

namespace App\Actions\Postsyncer;

use App\Data\Postsyncer\RepairPostAccountMappingData;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Models\Workspace;
use App\Support\Postsyncer\LegacyPublishProgress;
use App\Support\Postsyncer\MapPostsyncerAccounts;
use App\Support\Postsyncer\PostPublishPlanner;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PublishGroup;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
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
        ?string $runToken = null,
        ?string $operationId = null,
        ?string $leaseId = null,
    ): void {
        $post->refresh();
        $originalStatus = $post->status;
        $publishError = $post->publish_error;
        $options = $this->normalizeOptions($options);
        $progress = $post->publish_progress;

        // Direct callers from before run fencing can resume the current
        // checkpoint; queued jobs guard legacy no-token deliveries themselves.
        $runToken ??= is_string($progress['run_token'] ?? null)
            ? $progress['run_token']
            : (string) Str::uuid();

        // A duplicate delivery after finalization must not create another
        // PostSyncer post.
        if ($post->publish_state === 'succeeded' && $this->hasExistingPublicGroup($post)) {
            return;
        }

        $claimLeaseId = null;

        try {
            $claimed = $this->claimPost($post, $options, $runToken, $operationId, $leaseId);

            if ($claimed === null) {
                return;
            }

            $post = $claimed;
            $progress = $post->publish_progress;
            $claimLeaseId = $post->publish_lease_id;
            $post->loadMissing('workspace');
            $this->assertFirstPublish($post);
            $config = PostsyncerConfig::fromWorkspace($post->workspace);
            if (! $config->publishEnabled()) {
                throw new PostsyncerException('PostSyncer publishing is disabled in Settings.');
            }
            $client = new PostsyncerClient($config);
            $groups = $this->planner->plan($post, $config, $options);

            if ($groups === []) {
                throw new PostsyncerException(
                    'No PostSyncer publish groups could be planned for this post.'
                );
            }

            $plan = $this->planMetadata($config, $groups, $options);
            $progress = $this->prepareProgress(
                $post,
                $progress,
                $plan,
                $runToken,
                $publishError,
                $config,
                $groups,
                $claimLeaseId,
            );
            if ($progress === null) {
                return;
            }
            $this->assertAccountsConfigured($config, $groups);
            $completedGroups = $this->completedGroups($progress);

            if (! $this->allPlannedGroupsCompleted($progress)) {
                foreach ($groups as $index => $group) {
                    if (! $this->assertPlanUnchanged($post, $options, $plan, $runToken, $claimLeaseId)) {
                        return;
                    }
                    $groupKey = $this->groupKey($config, $group);
                    $idempotencyKey = $this->idempotencyKey(
                        (string) $progress['operation_id'],
                        $index,
                        $groupKey,
                    );

                    if ($this->completedGroup($completedGroups, $index, $groupKey) !== null) {
                        continue;
                    }

                    $mediaIds = $this->reconciledMediaIdsForGroup($progress, $index, $groupKey);
                    if ($mediaIds === null) {
                        $mediaIds = [];
                        $progress['state'] = 'running';
                        $progress['current'] = [
                            'index' => $index,
                            'group_key' => $groupKey,
                            'phase' => $group->mediaUrls !== [] ? 'uploading' : 'creating',
                            'idempotency_key' => $idempotencyKey,
                            'media_ids' => [],
                            'media_urls' => $group->mediaUrls,
                        ];
                        if (! $this->saveProgressForRun($post, $progress, $runToken, $claimLeaseId)) {
                            return;
                        }

                        if ($group->mediaUrls !== []) {
                            $mediaIds = $client->uploadFromUrls(
                                $group->workspaceId,
                                $group->mediaUrls,
                                $idempotencyKey.':media',
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
                            if (! $this->saveProgressForRun($post, $progress, $runToken, $claimLeaseId)) {
                                return;
                            }
                        }
                    }

                    $post->refresh();
                    if (! $this->runTokenMatches($post->publish_progress, $runToken)
                        || $post->publish_lease_id !== $claimLeaseId) {
                        return;
                    }

                    $body = $this->buildPostBody($config, $group, $mediaIds);

                    $progress['state'] = 'running';
                    $progress['current'] = [
                        'index' => $index,
                        'group_key' => $groupKey,
                        'phase' => 'creating',
                        'idempotency_key' => $idempotencyKey,
                        'media_ids' => $mediaIds,
                        'media_urls' => $group->mediaUrls,
                        'expected_payload' => $body,
                    ];
                    if (! $this->saveProgressForRun($post, $progress, $runToken, $claimLeaseId)) {
                        return;
                    }

                    $result = $client->createPost($body, $idempotencyKey.':post');
                    $post->refresh();
                    if (! $this->runTokenMatches($post->publish_progress, $runToken)
                        || $post->publish_lease_id !== $claimLeaseId) {
                        return;
                    }
                    $verified = $this->verifyCreatedPost(
                        $client,
                        $result,
                        $config,
                        $group,
                        $mediaIds,
                    );
                    $post->refresh();
                    if (! $this->runTokenMatches($post->publish_progress, $runToken)
                        || $post->publish_lease_id !== $claimLeaseId) {
                        return;
                    }
                    $completedGroups[] = $this->formatProgressGroup(
                        $group,
                        $verified,
                        $index,
                        $groupKey,
                        false,
                        $body,
                    );
                    usort(
                        $completedGroups,
                        fn (array $left, array $right): int => ((int) $left['index']) <=> ((int) $right['index']),
                    );

                    // Checkpoint each external create. A later group may fail or
                    // the worker may be restarted before the whole plan finishes.
                    $progress['completed_groups'] = $completedGroups;
                    $progress['current'] = null;
                    if (! $this->saveProgressForRun($post, $progress, $runToken, $claimLeaseId)) {
                        return;
                    }
                }
            }

            $operationComplete = $this->allPlannedGroupsCompleted($progress);
            if (! $operationComplete) {
                throw new PostsyncerException(
                    'PostSyncer publish progress is incomplete. Retry the publish.'
                );
            }

            $publishedGroups = array_map(
                fn (array $completed): array => $this->publicGroup($completed),
                $completedGroups,
            );
            $publishedGroups = array_merge(
                $publishedGroups,
                $this->supplementalGroups($progress),
            );

            $progress['state'] = 'succeeded';
            $progress['completed_groups'] = $completedGroups;
            $progress['current'] = null;
            if (! $this->finalizeSuccess(
                $post,
                $publishedGroups,
                $progress,
                $options,
                $claimLeaseId,
            )) {
                throw new PostsyncerException(
                    'The publish could not be finalized because its approval or cancellation state changed.'
                );
            }
        } catch (Throwable $e) {
            $unknownOutcome = $this->recordFailureForRun(
                $post,
                $runToken,
                $originalStatus,
                is_array($progress) ? $progress : null,
                $e,
                $claimLeaseId,
                $operationId,
                $options,
            );

            if ($unknownOutcome === null) {
                return;
            }

            // Keep deterministic PostSyncer errors in the record. Only
            // failures that are safe to repeat should reach the queue worker.
            if (! $unknownOutcome
                && ! ($e instanceof PostsyncerException && ! $e->retryable)
                && ! ($e instanceof \InvalidArgumentException)) {
                throw $e;
            }

            // A transient media response must reach the worker's retry policy,
            // but the persisted `uploading` checkpoint prevents that retry from
            // uploading the same asset again after an uncertain response.
            $phase = is_array($progress['current'] ?? null)
                ? ($progress['current']['phase'] ?? null)
                : null;
            if ($phase === 'uploading'
                && (($e instanceof PostsyncerException && $e->retryable)
                    || $e instanceof ConnectionException)
                && ! ($e instanceof PostsyncerException && $e->safeToRetry)) {
                throw $e;
            }
        }
    }

    /**
     * Reconcile the current group after a create response was lost. The
     * operator supplies the PostSyncer id after verifying it in PostSyncer;
     * this method verifies the id belongs to the expected workspace and
     * payload before checkpointing it.
     *
     * @param  array<int, mixed>  $supplementalGroups
     */
    public function reconcile(
        Post $post,
        int|string $postsyncerPostId,
        bool $confirmFailed = false,
        array $supplementalGroups = [],
    ): void {
        if (! $this->hasNumericPostId($postsyncerPostId)) {
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
            || ($progress['current']['phase'] ?? null) !== 'creating') {
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
            || $this->groupKey($config, $group) !== $current['group_key']) {
            throw new PostsyncerException(
                'The PostSyncer reconciliation group no longer matches the publish plan.'
            );
        }

        $client = new PostsyncerClient($config);
        $remote = $this->normalizePostResponse($client->getPostWithAccountDetails($postsyncerPostId));
        $remoteStatus = strtoupper((string) ($remote['status'] ?? ''));
        $failedPlatforms = $this->failedPlatforms($remote);
        $isPartial = $remoteStatus === 'PARTIALLY_FAILED';

        $supplementalPublicGroups = $this->verifySupplementalGroups(
            $client,
            $config,
            $group,
            $failedPlatforms,
            $supplementalGroups,
        );

        if ($isPartial) {
            if (! $confirmFailed) {
                throw new PostsyncerException(
                    'A partially failed PostSyncer post requires explicit confirmation and replacement groups.',
                );
            }

            $replacementPlatforms = [];
            foreach ($supplementalPublicGroups as $supplemental) {
                foreach ($supplemental['platforms'] as $platform) {
                    $replacementPlatforms[] = $platform;
                }
            }
            sort($replacementPlatforms);
            $expectedFailedPlatforms = $failedPlatforms;
            sort($expectedFailedPlatforms);

            if ($replacementPlatforms !== $expectedFailedPlatforms) {
                throw new PostsyncerException(
                    'Replacement PostSyncer groups must cover every failed platform exactly once.',
                );
            }
        } elseif ($supplementalPublicGroups !== []) {
            throw new PostsyncerException(
                'Supplemental PostSyncer groups are only valid for a partially failed post.',
            );
        }

        $this->assertReconciledPost(
            $remote,
            $config,
            $group,
            $current['media_ids'],
            $postsyncerPostId,
            $confirmFailed,
            null,
            $isPartial && $supplementalPublicGroups !== [],
        );

        DB::transaction(function () use (
            $post,
            $progress,
            $current,
            $remote,
            $index,
            $postsyncerPostId,
            $confirmFailed,
            $isPartial,
            $supplementalPublicGroups,
            $failedPlatforms,
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
                || ($latestProgress['current']['group_key'] ?? null) !== $current['group_key']) {
                throw new PostsyncerException(
                    'The PostSyncer progress changed while reconciliation was running.'
                );
            }

            $lockedPost->loadMissing('workspace');
            $latestConfig = PostsyncerConfig::fromWorkspace($lockedPost->workspace);
            $latestGroups = $this->planner->plan($lockedPost, $latestConfig, $latestProgress['options']);
            $latestGroup = $latestGroups[$current['index']] ?? null;

            if (! $latestGroup instanceof PublishGroup
                || $this->groupKey($latestConfig, $latestGroup) !== $current['group_key']) {
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
                $confirmFailed,
                null,
                $isPartial && $supplementalPublicGroups !== [],
            );

            $reconciledGroup = $this->formatProgressGroup(
                $latestGroup,
                $remote,
                $index,
                $current['group_key'],
                $confirmFailed && in_array(
                    strtoupper((string) ($remote['status'] ?? '')),
                    ['FAILED', 'PARTIALLY_FAILED'],
                    true,
                ),
                $this->buildPostBody($latestConfig, $latestGroup, $current['media_ids']),
            );
            if ($isPartial) {
                $reconciledGroup['platforms'] = array_values(array_filter(
                    $reconciledGroup['platforms'],
                    fn (string $platform): bool => ! in_array($platform, $failedPlatforms, true),
                ));
            }
            $completedGroups = $this->upsertCompletedGroup(
                $this->completedGroups($latestProgress),
                $reconciledGroup,
            );
            usort(
                $completedGroups,
                fn (array $left, array $right): int => ((int) $left['index']) <=> ((int) $right['index']),
            );

            $latestProgress['completed_groups'] = $completedGroups;
            $latestProgress['current'] = null;
            $latestProgress['state'] = 'failed';
            if ($supplementalPublicGroups !== []) {
                $latestProgress['supplemental_groups'] = $supplementalPublicGroups;
            }
            $lockedPost->forceFill([
                'publish_state' => 'failed',
                'publish_error' => 'PostSyncer post '.(string) $postsyncerPostId
                    .' reconciled. Retry to continue the publish.',
                'publish_progress' => $latestProgress,
            ])->save();
        });

        $post->refresh();
    }

    /**
     * Checkpoint media ids after an upload response was lost. PostSyncer does
     * not expose an idempotency key for media imports, so retrying the upload
     * is not safe until an operator supplies the ids already present there.
     *
     * @param  list<int|string>  $mediaIds
     */
    public function reconcileMedia(Post $post, array $mediaIds): void
    {
        $post->refresh();
        $progress = $post->publish_progress;

        if (! is_array($progress)) {
            throw new PostsyncerException('This post has no PostSyncer progress to reconcile.');
        }

        $this->assertProgressShape($progress);
        $current = $progress['current'] ?? null;

        if (($progress['state'] ?? null) !== 'uncertain'
            || ! is_array($current)
            || ($current['phase'] ?? null) !== 'uploading') {
            throw new PostsyncerException(
                'This post does not have an uncertain PostSyncer media upload to reconcile.'
            );
        }

        $expectedCount = is_array($current['media_urls'] ?? null)
            ? count($current['media_urls'])
            : 0;
        $normalized = $this->normalizeMediaIds($mediaIds);

        if ($expectedCount === 0 || count($normalized) !== $expectedCount) {
            throw new PostsyncerException(
                "Supply exactly {$expectedCount} PostSyncer media ids in upload order."
            );
        }

        DB::transaction(function () use ($post, $progress, $current, $normalized): void {
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
                || ($latestProgress['current']['phase'] ?? null) !== 'uploading') {
                throw new PostsyncerException(
                    'The PostSyncer progress changed while media reconciliation was running.'
                );
            }

            $latestProgress['current']['phase'] = 'retryable';
            $latestProgress['current']['media_ids'] = $normalized;
            $latestProgress['state'] = 'failed';
            $lockedPost->forceFill([
                'publish_state' => 'failed',
                'publish_error' => 'PostSyncer media upload reconciled. Retry the publish to create the post.',
                'publish_progress' => $latestProgress,
            ])->save();
        });

        $post->refresh();
    }

    /**
     * Rebind one stale PostSyncer account and rebase the unfinished group of
     * a failed publish. Completed external groups must remain byte-for-byte
     * on the old plan; otherwise this operation refuses to change anything.
     */
    public function repairAccountMapping(
        Post $post,
        Workspace $workspace,
        RepairPostAccountMappingData $mapping,
    ): void {
        abort_if($post->workspace_id !== $workspace->id, 404);

        $post->refresh();
        $workspace->refresh();
        $config = PostsyncerConfig::fromWorkspace($workspace);
        $languageConfig = $config->language($mapping->language);
        $postsyncerWorkspaceId = $languageConfig['workspace_id'];

        if ($postsyncerWorkspaceId === null) {
            throw new PostsyncerException(
                "No PostSyncer workspace is configured for {$mapping->language}."
            );
        }

        $targetAccount = $this->findRepairAccount(
            new PostsyncerClient($config),
            $postsyncerWorkspaceId,
            $mapping,
        );
        $targetHandle = MapPostsyncerAccounts::accountHandle(
            $targetAccount,
            $postsyncerWorkspaceId,
        );

        DB::transaction(function () use (
            $post,
            $workspace,
            $mapping,
            $targetHandle,
        ): void {
            $lockedWorkspace = Workspace::query()
                ->whereKey($workspace->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedPost = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($lockedPost->workspace_id !== $lockedWorkspace->id, 404);

            if ($this->hasExistingPublicGroup($lockedPost)) {
                throw new PostsyncerException(
                    'This post already has public PostSyncer groups. Account mapping repair is not safe.'
                );
            }

            $progress = $lockedPost->publish_progress;
            if (! is_array($progress)) {
                throw new PostsyncerException(
                    'This post has no partial PostSyncer publish progress to repair.'
                );
            }

            $this->assertProgressShape($progress);

            if ($lockedPost->publish_state !== 'failed'
                || ($progress['state'] ?? null) !== 'failed'
                || ! is_array($progress['current'] ?? null)
                || ($progress['current']['phase'] ?? null) !== 'retryable') {
                throw new PostsyncerException(
                    'Only a failed, retryable PostSyncer group can be repaired.'
                );
            }

            if ($this->supplementalGroups($progress) !== []) {
                throw new PostsyncerException(
                    'Supplemental PostSyncer groups must be reconciled before account mapping repair.'
                );
            }

            $oldConfig = PostsyncerConfig::fromWorkspace($lockedWorkspace);
            $oldLanguageConfig = $oldConfig->language($mapping->language);
            $oldPlatformConfig = is_array(
                $oldLanguageConfig['platforms'][$mapping->platform] ?? null,
            )
                ? $oldLanguageConfig['platforms'][$mapping->platform]
                : [];
            $currentAccountId = $oldPlatformConfig['account_id'] ?? null;

            if ((string) $currentAccountId !== $mapping->fromAccountId) {
                throw new PostsyncerException(
                    'The current PostSyncer account mapping changed. Reload the post before repairing it.'
                );
            }

            $options = $progress['options'];
            $oldGroups = $this->planner->plan($lockedPost, $oldConfig, $options);
            $oldPlan = $this->planMetadata($oldConfig, $oldGroups, $options);

            if (($progress['plan_hash'] ?? null) !== $oldPlan['hash']
                || ($progress['planned_groups'] ?? null) !== $oldPlan['groups']) {
                throw new PostsyncerException(
                    'The stored PostSyncer plan no longer matches the current post or settings.'
                    .' Reconcile it before repairing the account mapping.'
                );
            }

            /** @var array<string, mixed> $current */
            $current = $progress['current'];
            $currentIndex = $current['index'] ?? null;
            $oldCurrentKey = $current['group_key'] ?? null;
            $oldCurrentGroup = is_int($currentIndex)
                ? ($oldGroups[$currentIndex] ?? null)
                : null;
            $oldPlanned = is_int($currentIndex)
                ? ($oldPlan['groups'][$currentIndex] ?? null)
                : null;

            if (! is_int($currentIndex)
                || ! $oldCurrentGroup instanceof PublishGroup
                || ! is_array($oldPlanned)
                || ! is_string($oldCurrentKey)
                || ! in_array($mapping->platform, $oldCurrentGroup->platforms, true)
                || $oldPlanned['group_key'] !== $oldCurrentKey
                || $this->groupKey($oldConfig, $oldCurrentGroup) !== $oldCurrentKey) {
                throw new PostsyncerException(
                    'The failed PostSyncer group no longer matches its stored plan.'
                );
            }

            $completedGroups = $this->completedGroups($progress);
            if ($completedGroups === []) {
                throw new PostsyncerException(
                    'This publish has no completed groups; repair the account mapping before starting it.'
                );
            }

            $this->assertCompletedGroupsBelongToPlan($progress, $oldPlan['groups']);
            $completedByIndex = [];
            foreach ($completedGroups as $completed) {
                $completedIndex = $completed['index'] ?? null;
                if (! is_int($completedIndex) || $completedIndex >= $currentIndex) {
                    throw new PostsyncerException(
                        'The failed group is not the next group after the stored completed groups.'
                    );
                }

                if (isset($completedByIndex[$completedIndex])) {
                    throw new PostsyncerException(
                        'PostSyncer publish progress contains duplicate completed groups.'
                    );
                }

                $completedByIndex[$completedIndex] = true;
            }

            for ($index = 0; $index < $currentIndex; $index++) {
                if (! isset($completedByIndex[$index])) {
                    throw new PostsyncerException(
                        'The failed group is not the next group after the stored completed groups.'
                    );
                }
            }

            $currentMediaUrls = $current['media_urls'] ?? null;
            if (! is_array($currentMediaUrls)
                || count(array_filter(
                    $currentMediaUrls,
                    static fn (mixed $url): bool => is_string($url),
                )) !== count($currentMediaUrls)
                || $this->canonicalMediaUrls($currentMediaUrls)
                    !== $this->canonicalMediaUrls($oldCurrentGroup->mediaUrls)) {
                throw new PostsyncerException(
                    'The failed group media changed. Reconcile it before repairing the account mapping.'
                );
            }

            $mediaIds = $current['media_ids'];
            if (count($mediaIds) !== count($oldCurrentGroup->mediaUrls)
                || count(array_unique(array_map('strval', $mediaIds))) !== count($mediaIds)
                || count(array_filter(
                    $mediaIds,
                    fn (mixed $mediaId): bool => $this->hasNumericPostId($mediaId),
                )) !== count($mediaIds)) {
                throw new PostsyncerException(
                    'The failed group media checkpoint is invalid. Reconcile it before repairing the account mapping.'
                );
            }

            $existingHandle = $oldPlatformConfig['handle'] ?? '';
            $handle = $targetHandle !== ''
                ? $targetHandle
                : (is_string($existingHandle) ? $existingHandle : '');
            $enabled = MapPostsyncerAccounts::enabled($oldPlatformConfig, true);

            PostsyncerConfig::write($lockedWorkspace, [
                'languages' => [
                    $mapping->language => [
                        'platforms' => [
                            $mapping->platform => [
                                'account_id' => $mapping->toAccountId,
                                'handle' => $handle,
                                'enabled' => $enabled,
                            ],
                        ],
                    ],
                ],
            ]);

            $updatedConfig = PostsyncerConfig::fromWorkspace($lockedWorkspace);
            $updatedGroups = $this->planner->plan($lockedPost, $updatedConfig, $options);
            $updatedPlan = $this->planMetadata($updatedConfig, $updatedGroups, $options);
            $updatedCurrentGroup = $updatedGroups[$currentIndex] ?? null;
            $updatedCurrentPlan = $updatedPlan['groups'][$currentIndex] ?? null;

            if (! $updatedCurrentGroup instanceof PublishGroup
                || ! is_array($updatedCurrentPlan)
                || $updatedCurrentPlan['group_key'] === $oldCurrentKey
                || $this->canonicalMediaUrls($updatedCurrentGroup->mediaUrls)
                    !== $this->canonicalMediaUrls($oldCurrentGroup->mediaUrls)) {
                throw new PostsyncerException(
                    'The account mapping did not produce a safe replacement for the failed group.'
                );
            }

            foreach ($completedGroups as $completed) {
                $completedIndex = $completed['index'];
                $updatedPlanned = $updatedPlan['groups'][$completedIndex] ?? null;
                if (! is_array($updatedPlanned)
                    || $updatedPlanned['group_key'] !== $completed['group_key']) {
                    throw new PostsyncerException(
                        'Account mapping repair would change a completed PostSyncer group.'
                    );
                }
            }

            $newGroupKey = $updatedCurrentPlan['group_key'];
            $current['group_key'] = $newGroupKey;
            $current['phase'] = 'retryable';
            $current['idempotency_key'] = $this->idempotencyKey(
                (string) $progress['operation_id'],
                $currentIndex,
                $newGroupKey,
            );
            $current['media_urls'] = $updatedCurrentGroup->mediaUrls;
            $current['expected_payload'] = $this->buildPostBody(
                $updatedConfig,
                $updatedCurrentGroup,
                $mediaIds,
            );
            $progress['plan_hash'] = $updatedPlan['hash'];
            $progress['planned_groups'] = $updatedPlan['groups'];
            $progress['current'] = $current;
            $progress['state'] = 'failed';
            $progress['account_mapping_repair'] = [
                'language' => $mapping->language,
                'platform' => $mapping->platform,
                'from_account_id' => $mapping->fromAccountId,
                'to_account_id' => $mapping->toAccountId,
            ];

            $lockedPost->forceFill([
                'publish_state' => 'failed',
                'publish_error' => 'PostSyncer account mapping repaired from '
                    .$mapping->fromAccountId.' to '.$mapping->toAccountId
                    .'. Retry to continue the publish.',
                'publish_progress' => $progress,
                'publish_claimed_at' => null,
                'publish_lease_id' => null,
            ])->save();
        });

        $post->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function findRepairAccount(
        PostsyncerClient $client,
        string $postsyncerWorkspaceId,
        RepairPostAccountMappingData $mapping,
    ): array {
        foreach ($client->listAccounts($postsyncerWorkspaceId) as $account) {
            if ((string) ($account['id'] ?? '') !== $mapping->toAccountId) {
                continue;
            }

            if (MapPostsyncerAccounts::platformName($account['platform'] ?? '') !== $mapping->platform) {
                throw new PostsyncerException(
                    "PostSyncer account {$mapping->toAccountId} is not a {$mapping->platform} account."
                );
            }

            if (filter_var($account['has_expired'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw new PostsyncerException(
                    "PostSyncer account {$mapping->toAccountId} is expired."
                );
            }

            return $account;
        }

        throw new PostsyncerException(
            "PostSyncer account {$mapping->toAccountId} was not found in workspace {$postsyncerWorkspaceId}."
        );
    }

    /**
     * Recover an operation whose local content or settings drifted after all
     * of its external groups were created. This is deliberately separate from
     * normal retry: the operator is accepting the persisted old payload.
     */
    public function recoverPlanDrift(Post $post, bool $confirmFailed = false): void
    {
        $post->refresh();
        $progress = $post->publish_progress;

        if (! is_array($progress)) {
            throw new PostsyncerException('This post has no PostSyncer progress to recover.');
        }

        $this->assertProgressShape($progress);

        if (($progress['state'] ?? null) !== 'failed'
            || ($progress['current'] ?? null) !== null
            || $this->completedGroups($progress) === []) {
            throw new PostsyncerException(
                'This post does not have a fully checkpointed PostSyncer operation with plan drift.'
            );
        }

        $storedHash = $progress['plan_hash'] ?? null;
        $storedGroups = $progress['planned_groups'] ?? null;
        if (! is_string($storedHash)
            || ! is_array($storedGroups)
            || ! array_is_list($storedGroups)
            || $storedGroups === []) {
            throw new PostsyncerException(
                'This post has no recoverable PostSyncer plan metadata.'
            );
        }

        /** @var list<array{index: int, group_key: string}> $storedGroups */
        $this->assertCompletedGroupsBelongToPlan($progress, $storedGroups);

        if (! $this->allPlannedGroupsCompleted($progress)) {
            throw new PostsyncerException(
                'This post has unfinished PostSyncer groups. Plan drift cannot be recovered safely; clean up the created groups before starting a new publish.'
            );
        }

        $post->loadMissing('workspace');
        $config = PostsyncerConfig::fromWorkspace($post->workspace);
        $currentPlan = $this->planMetadata(
            $config,
            $this->planner->plan($post, $config, $progress['options']),
            $progress['options'],
        );

        if ($storedHash === $currentPlan['hash'] && $storedGroups === $currentPlan['groups']) {
            throw new PostsyncerException('The PostSyncer publish plan has not drifted.');
        }

        $completedGroups = $this->completedGroups($progress);
        $client = new PostsyncerClient($config);

        foreach ($storedGroups as $planned) {
            $index = $planned['index'];
            $groupKey = $planned['group_key'];
            $completed = $this->completedGroup($completedGroups, $index, $groupKey);

            if ($completed === null || ! is_array($completed['expected_payload'] ?? null)) {
                throw new PostsyncerException(
                    'This post is missing the payload snapshot required for plan-drift recovery.'
                );
            }

            $expectedPayload = $completed['expected_payload'];
            $snapshotGroup = $this->publishGroupFromSnapshot($completed, $expectedPayload);
            $remote = $this->normalizePostResponse($client->getPostWithAccountDetails($completed['post_id']));
            $this->assertReconciledPost(
                $remote,
                $config,
                $snapshotGroup,
                [],
                $completed['post_id'],
                $confirmFailed || ($completed['operator_confirmed'] ?? false) === true,
                $expectedPayload,
            );
        }

        $publishedGroups = array_map(
            fn (array $completed): array => $this->publicGroup($completed),
            $completedGroups,
        );
        $publishedGroups = array_merge(
            $publishedGroups,
            $this->supplementalGroups($progress),
        );
        $progress['state'] = 'succeeded';
        $progress['current'] = null;
        $progress['plan_drift_recovered'] = true;

        DB::transaction(function () use (
            $post,
            $progress,
            $completedGroups,
            $publishedGroups,
            $currentPlan,
        ): void {
            Workspace::query()
                ->whereKey($post->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPost = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $latestProgress = $lockedPost->publish_progress;

            if (! is_array($latestProgress)
                || ($latestProgress['operation_id'] ?? null) !== ($progress['operation_id'] ?? null)
                || ($latestProgress['state'] ?? null) !== 'failed'
                || ($latestProgress['current'] ?? null) !== null
                || $this->completedGroups($latestProgress) !== $completedGroups) {
                throw new PostsyncerException(
                    'The post publish progress changed while plan-drift recovery was running.'
                );
            }

            $lockedPost->loadMissing('workspace');
            $latestConfig = PostsyncerConfig::fromWorkspace($lockedPost->workspace);
            $latestPlan = $this->planMetadata(
                $latestConfig,
                $this->planner->plan($lockedPost, $latestConfig, $latestProgress['options']),
                $latestProgress['options'],
            );
            if ($latestPlan['hash'] !== $currentPlan['hash']
                || $latestPlan['groups'] !== $currentPlan['groups']) {
                throw new PostsyncerException(
                    'The post changed while plan-drift recovery was running.'
                );
            }

            $lockedPost->forceFill([
                'postsyncer' => ['groups' => $publishedGroups],
                'status' => $this->hasScheduledCompletedGroup($publishedGroups) ? 'scheduled' : 'posted',
                'publish_state' => 'succeeded',
                'publish_error' => null,
                'publish_progress' => $progress,
            ])->save();
        });

        $post->refresh();
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
     * Validate all account mappings before any media is registered remotely.
     * The progress checkpoint is already persisted, so a settings fix can be
     * applied and retried without leaving an uncertain media upload behind.
     *
     * @param  list<PublishGroup>  $groups
     */
    private function assertAccountsConfigured(PostsyncerConfig $config, array $groups): void
    {
        foreach ($groups as $group) {
            $this->buildAccounts(
                $config->language($group->language)['platforms'],
                $group,
                is_array($group->threadTweets) && $group->threadTweets !== [],
            );
        }
    }

    private function hasDefinitiveResponse(Throwable $exception): bool
    {
        return $exception instanceof PostsyncerException
            && $exception->responseReceived
            && ! $exception->outcomeUnknown;
    }

    /**
     * Verify a successful create through PostSyncer's canonical resource. A
     * create has already happened when this lookup starts, so an unavailable
     * or mismatched resource remains an uncertain outcome and is never replayed
     * automatically.
     *
     * @param  array<string, mixed>  $created
     * @param  list<int|string>  $mediaIds
     * @return array<string, mixed>
     */
    private function verifyCreatedPost(
        PostsyncerClient $client,
        array $created,
        PostsyncerConfig $config,
        PublishGroup $group,
        array $mediaIds,
    ): array {
        $created = $this->normalizePostResponse($created);
        $postId = $created['id'] ?? null;

        if (! $this->hasNumericPostId($postId)) {
            throw new PostsyncerException(
                'PostSyncer accepted the create but returned no verifiable post id.',
                0,
                null,
                false,
                true,
                true,
            );
        }

        $lastException = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) {
                usleep(100000 * $attempt);
            }

            $remote = [];

            try {
                $remote = $this->normalizePostResponse($client->getPostWithAccountDetails((string) $postId));
                $this->assertReconciledPost($remote, $config, $group, $mediaIds, $postId);

                return $remote;
            } catch (Throwable $exception) {
                $lastException = $exception;

                if (! $this->shouldRetryCanonicalLookup($remote, $exception)) {
                    break;
                }
            }
        }

        $message = 'PostSyncer accepted post '.(string) $postId
            .' but its canonical payload could not be verified.';
        if ($lastException->getMessage() !== '') {
            $message .= ' '.$lastException->getMessage();
        }

        throw new PostsyncerException(
            $message.' Reconcile PostSyncer before retrying.',
            $lastException instanceof PostsyncerException ? $lastException->getCode() : 0,
            $lastException,
            false,
            true,
            true,
        );
    }

    /**
     * PostSyncer may expose a newly-created record before its canonical
     * payload is populated. Retry only those transient/asynchronous states;
     * a stable payload mismatch should go straight to reconciliation.
     *
     * @param  array<string, mixed>  $remote
     */
    private function shouldRetryCanonicalLookup(array $remote, Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof PostsyncerException) {
            if (! $exception->responseReceived && $exception->getPrevious() instanceof ConnectionException) {
                return true;
            }

            $code = (int) $exception->getCode();

            if (in_array($code, [404, 408, 425, 429], true) || $code >= 500) {
                return true;
            }
        }

        return in_array(
            strtoupper((string) ($remote['status'] ?? '')),
            ['IN_QUEUE', 'PENDING', 'QUEUED'],
            true,
        );
    }

    /**
     * Prefer the latest persisted progress, but never discard a local current
     * group when its checkpoint write failed. Keeping that group is safer than
     * replaying a create whose response may already have been accepted.
     *
     * @param  array<string, mixed>|null  $local
     * @param  array<string, mixed>|null  $latest
     * @return array<string, mixed>|null
     */
    private function failureProgress(?array $local, ?array $latest): ?array
    {
        if ($local === null) {
            return $latest;
        }

        if ($latest === null
            || ($local['operation_id'] ?? null) !== ($latest['operation_id'] ?? null)) {
            return $local;
        }

        $merged = $latest;
        $completed = $this->completedGroups($latest);
        foreach ($this->completedGroups($local) as $localGroup) {
            $index = $localGroup['index'] ?? null;
            $groupKey = $localGroup['group_key'] ?? null;

            if (is_int($index)
                && is_string($groupKey)
                && $this->completedGroup($completed, $index, $groupKey) === null) {
                $completed[] = $localGroup;
            }
        }

        usort(
            $completed,
            fn (array $left, array $right): int => ((int) ($left['index'] ?? 0))
                <=> ((int) ($right['index'] ?? 0)),
        );
        $merged['completed_groups'] = $completed;

        $localCurrent = $local['current'] ?? null;
        $latestCurrent = $latest['current'] ?? null;
        $localCurrentAlreadyCompleted = is_array($localCurrent)
            && is_int($localCurrent['index'] ?? null)
            && is_string($localCurrent['group_key'] ?? null)
            && $this->completedGroup(
                $completed,
                $localCurrent['index'],
                $localCurrent['group_key'],
            ) !== null;

        if (is_array($localCurrent)
            && $latestCurrent === null
            && ! $localCurrentAlreadyCompleted) {
            $merged['current'] = $localCurrent;
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $remote
     * @param  list<int|string>  $mediaIds
     * @param  array<string, mixed>|null  $expectedPayload
     */
    private function assertReconciledPost(
        array $remote,
        PostsyncerConfig $config,
        PublishGroup $group,
        array $mediaIds,
        int|string $postsyncerPostId,
        bool $allowFailed = false,
        ?array $expectedPayload = null,
        bool $allowPartial = false,
    ): void {
        if ((string) ($remote['id'] ?? '') !== (string) $postsyncerPostId) {
            throw new PostsyncerException('The supplied PostSyncer post id was not found.');
        }

        $remoteWorkspaceId = $remote['workspace_id'] ?? data_get($remote, 'workspace.id');
        if ($remoteWorkspaceId === null
            || (string) $remoteWorkspaceId !== (string) $group->workspaceId) {
            throw new PostsyncerException(
                'The supplied PostSyncer post belongs to a different workspace.'
            );
        }

        $expected = $expectedPayload ?? $this->buildPostBody($config, $group, $mediaIds);
        $expectedContent = $expected['content'] ?? null;
        if (! is_array($expectedContent)) {
            throw new PostsyncerException(
                'The supplied PostSyncer payload snapshot has no content to verify.'
            );
        }
        $remoteContent = $remote['content'] ?? null;

        if (! is_array($remoteContent) || count($remoteContent) !== count($expectedContent)) {
            throw new PostsyncerException(
                'The supplied PostSyncer post does not match the current publish group.'
            );
        }

        foreach ($expectedContent as $index => $expectedItem) {
            if (! is_array($expectedItem)) {
                throw new PostsyncerException(
                    'The supplied PostSyncer payload snapshot has invalid content details.'
                );
            }

            $remoteItem = $remoteContent[$index] ?? null;

            $expectedMedia = is_array($expectedItem['media'] ?? null)
                ? $expectedItem['media']
                : [];

            if (! is_array($remoteItem)) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post does not match the current publish group.'
                );
            }

            $remoteMedia = $remoteItem['media'] ?? [];
            if (($remoteItem['text'] ?? null) !== ($expectedItem['text'] ?? null)
                || ! is_array($remoteMedia)
                || count($remoteMedia) !== count($expectedMedia)
                || $this->responseMediaIds($remoteMedia)
                    !== array_map('strval', $expectedMedia)) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post does not match the current publish group.'
                );
            }

            if ((bool) ($remoteItem['is_first_comment'] ?? false)
                !== (bool) ($expectedItem['is_first_comment'] ?? false)
                || (int) ($remoteItem['first_comment_delay'] ?? 0)
                    !== (int) ($expectedItem['first_comment_delay'] ?? 0)) {
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
        if (count($actualPlatforms) !== count($remotePlatforms)) {
            throw new PostsyncerException(
                'The supplied PostSyncer post does not match the current publish group.'
            );
        }
        sort($actualPlatforms);
        sort($expectedPlatforms);

        if ($actualPlatforms !== $expectedPlatforms) {
            throw new PostsyncerException(
                'The supplied PostSyncer post does not match the current publish group.'
            );
        }

        $expectedAccounts = $expectedPayload !== null
            ? ($expectedPayload['accounts'] ?? null)
            : $this->buildAccounts(
                $config->language($group->language)['platforms'],
                $group,
                is_array($group->threadTweets) && $group->threadTweets !== [],
            );

        if (! is_array($expectedAccounts) || count($expectedAccounts) !== count($group->platforms)) {
            throw new PostsyncerException(
                'The supplied PostSyncer post does not contain verifiable account details.'
            );
        }

        foreach ($group->platforms as $platform) {
            $remotePlatform = null;
            foreach ($remotePlatforms as $candidate) {
                if (is_array($candidate)
                    && strtolower((string) ($candidate['platform'] ?? '')) === strtolower($platform)) {
                    $remotePlatform = $candidate;

                    break;
                }
            }

            if (! is_array($remotePlatform)) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post does not match the current publish group.'
                );
            }

            $expectedIndex = array_search($platform, $group->platforms, true);
            $expectedAccount = is_int($expectedIndex)
                ? ($expectedAccounts[$expectedIndex] ?? null)
                : null;
            $expectedAccountId = is_array($expectedAccount)
                ? ($expectedAccount['id'] ?? null)
                : null;
            $remoteAccountId = $remotePlatform['account_id'] ?? data_get($remotePlatform, 'account.id');
            if (! $this->hasExistingPostId($remoteAccountId)
                || (string) $remoteAccountId !== (string) $expectedAccountId) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post targets a different account.'
                );
            }

            $expectedSettings = is_array($expectedAccount)
                ? ($expectedAccount['settings'] ?? [])
                : [];
            $remoteSettings = $remotePlatform['settings'] ?? null;
            if ($expectedSettings !== []) {
                if (! is_array($remoteSettings)) {
                    throw new PostsyncerException(
                        'The supplied PostSyncer post has no platform settings to verify.'
                    );
                }

                foreach ($expectedSettings as $setting => $value) {
                    // PostSyncer stores Facebook and Instagram captions in
                    // content[0].text and may omit the redundant platform
                    // setting from the canonical resource. The content
                    // comparison above still verifies the exact caption.
                    if (! array_key_exists($setting, $remoteSettings)) {
                        if ($setting === 'caption'
                            && in_array(strtolower($platform), ['facebook', 'instagram'], true)) {
                            continue;
                        }

                        throw new PostsyncerException(
                            'The supplied PostSyncer post does not match the current platform settings.'
                        );
                    }

                    if ($remoteSettings[$setting] !== $value) {
                        throw new PostsyncerException(
                            'The supplied PostSyncer post does not match the current platform settings.'
                        );
                    }
                }
            }
        }

        $remoteStatus = strtoupper((string) ($remote['status'] ?? ''));
        if (! (($allowFailed && $remoteStatus === 'FAILED')
            || ($allowPartial && $remoteStatus === 'PARTIALLY_FAILED'))) {
            $this->assertPublishableStatus(
                $remote['status'] ?? null,
                $group,
                'The supplied PostSyncer post',
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
     * @param  array<string, mixed>  $remote
     * @return list<string>
     */
    private function failedPlatforms(array $remote): array
    {
        $failed = [];

        foreach ($remote['platforms'] ?? [] as $platform) {
            if (! is_array($platform)) {
                continue;
            }

            $status = strtoupper((string) ($platform['status'] ?? ''));
            $name = strtolower((string) ($platform['platform'] ?? ''));
            if ($name !== '' && in_array($status, ['FAILED', 'PARTIALLY_FAILED'], true)) {
                $failed[] = $name;
            }
        }

        return array_values(array_unique($failed));
    }

    /**
     * Verify replacement posts for platform rows that failed inside a
     * partially failed PostSyncer post.
     *
     * @param  list<string>  $failedPlatforms
     * @param  array<int, mixed>  $requested
     * @return list<array<string, mixed>>
     */
    private function verifySupplementalGroups(
        PostsyncerClient $client,
        PostsyncerConfig $config,
        PublishGroup $primaryGroup,
        array $failedPlatforms,
        array $requested,
    ): array {
        $verified = [];
        $seenPlatforms = [];

        foreach ($requested as $candidate) {
            if (! is_array($candidate)) {
                throw new PostsyncerException('Each supplemental PostSyncer group must be an object.');
            }

            $postId = $candidate['postsyncer_id'] ?? null;
            $platforms = $candidate['platforms'] ?? null;
            $mediaIds = $candidate['media_ids'] ?? null;

            if (! $this->hasNumericPostId($postId)
                || ! is_array($platforms)
                || $platforms === []
                || ! is_array($mediaIds)) {
                throw new PostsyncerException(
                    'Each supplemental PostSyncer group needs a post id, platforms, and media ids.',
                );
            }

            $normalizedPlatforms = array_values(array_map(
                static fn (mixed $platform): string => strtolower((string) $platform),
                $platforms,
            ));
            if (count($normalizedPlatforms) !== count(array_unique($normalizedPlatforms))) {
                throw new PostsyncerException('Supplemental PostSyncer group platforms must be unique.');
            }

            foreach ($normalizedPlatforms as $platform) {
                if ($platform === '' || ! in_array($platform, $failedPlatforms, true)) {
                    throw new PostsyncerException(
                        'Supplemental PostSyncer groups may only replace failed platforms.',
                    );
                }
                if (in_array($platform, $seenPlatforms, true)) {
                    throw new PostsyncerException(
                        'Each failed platform may have only one supplemental PostSyncer group.',
                    );
                }
                $seenPlatforms[] = $platform;
            }

            $normalizedMediaIds = $this->normalizeMediaIds($mediaIds);
            if ($normalizedMediaIds === []) {
                throw new PostsyncerException('Supplemental PostSyncer groups must contain media ids.');
            }

            $captions = [];
            foreach ($normalizedPlatforms as $platform) {
                $caption = $primaryGroup->captions[$platform] ?? null;
                if (! is_string($caption)) {
                    throw new PostsyncerException(
                        "No caption is available for supplemental platform {$platform}.",
                    );
                }
                $captions[$platform] = $caption;
            }

            $group = new PublishGroup(
                language: $primaryGroup->language,
                workspaceId: $primaryGroup->workspaceId,
                platforms: $normalizedPlatforms,
                mediaUrls: [],
                captions: $captions,
                when: $primaryGroup->when,
                publishNow: $primaryGroup->publishNow,
            );
            $remote = $this->normalizePostResponse(
                $client->getPostWithAccountDetails($postId),
            );
            $this->assertReconciledPost(
                $remote,
                $config,
                $group,
                $normalizedMediaIds,
                $postId,
            );

            $verified[] = $this->publicRemoteGroup($remote, $group, $postId);
        }

        return $verified;
    }

    /**
     * Keep the Telegram request state tied to the publish operation that owns
     * the post. Dashboard/API publishes simply have no matching request.
     *
     * @param  array<string, mixed>|null  $options
     */
    private function updateTelegramPostRequests(
        Post $post,
        string $state,
        ?string $errorMessage = null,
        ?array $options = null,
    ): void {
        if ($options === null) {
            return;
        }

        $requestId = $this->telegramRequestId($options);
        if ($requestId === null) {
            return;
        }

        $query = $post->telegramPostRequests()
            ->whereIn('state', [
                TelegramPostRequest::AWAITING_APPROVAL,
                TelegramPostRequest::APPROVED,
                TelegramPostRequest::FAILED,
            ])
            ->whereKey($requestId);

        $query->update([
            'state' => $state,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Admit one publish worker under a durable row lease. Cancellation and
     * approval checks use the same lock ordering as the worker.
     *
     * @param  array<string, mixed>  $options
     */
    private function claimPost(
        Post $post,
        array $options,
        string $runToken,
        ?string $operationId = null,
        ?string $leaseId = null,
    ): ?Post {
        return DB::transaction(function () use ($post, $options, $runToken, $operationId, $leaseId): ?Post {
            Workspace::query()
                ->whereKey($post->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();

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

            if (is_array($progress)
                && $this->hasRunToken($progress)
                && ! $this->runTokenMatches($progress, $runToken)) {
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

            $legacyAccountFailure = LegacyPublishProgress::isMissingAccountFailure(
                $locked->publish_error,
                is_array($progress) ? $progress : null,
            );

            if ($locked->publish_state === 'failed'
                && is_array($progress)
                && ! $legacyAccountFailure
                && ($this->hasUnknownCurrent($progress) || ($progress['state'] ?? null) === 'uncertain')
            ) {
                return null;
            }

            if ($locked->publish_state === 'running') {
                $claimedAt = $locked->publish_claimed_at;
                $staleAt = now()->subSeconds(PublishPostJob::LEASE_SECONDS);

                if ($claimedAt !== null && $claimedAt->greaterThan($staleAt)) {
                    return null;
                }

                $unknownOutcome = is_array($progress)
                    && ($this->hasUnknownCurrent($progress) || ($progress['state'] ?? null) === 'uncertain');

                if ($unknownOutcome) {
                    $progress['state'] = 'uncertain';
                    $error = 'PostSyncer create outcome is uncertain. Reconcile PostSyncer before retrying. '
                        .'The previous publish worker lease expired.';
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
                'publish_progress' => is_array($progress)
                    ? [...$progress, 'run_token' => $runToken]
                    : $progress,
            ])->save();

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function lockTelegramRequest(Post $post, array $options): ?TelegramPostRequest
    {
        $requestId = $this->telegramRequestId($options);
        if ($requestId === null) {
            return null;
        }

        $request = TelegramPostRequest::query()->whereKey($requestId)->first();

        if ($request === null
            || $request->workspace_id !== $post->workspace_id
            || $request->post_id !== $post->id) {
            throw new PostsyncerException('The Telegram publish request does not belong to this post.');
        }

        $config = TelegramBotConfig::query()
            ->whereKey($request->telegram_bot_config_id)
            ->lockForUpdate()
            ->first();

        if ($config === null
            || ! $config->isConnected()
            || ($request->webhook_generation !== null
                && $request->webhook_generation !== $config->webhook_generation)
        ) {
            throw new PostsyncerException(
                'The Telegram bot connection changed before this publish could run.',
            );
        }

        $request = TelegramPostRequest::query()
            ->whereKey($requestId)
            ->lockForUpdate()
            ->first();

        if ($request === null
            || $request->workspace_id !== $post->workspace_id
            || $request->post_id !== $post->id
            || ($request->webhook_generation !== null
                && $request->webhook_generation !== $config->webhook_generation)) {
            throw new PostsyncerException('The Telegram publish request does not belong to this post.');
        }

        if ($request->webhook_generation === null && $config->webhook_generation !== null) {
            $request->forceFill([
                'webhook_generation' => $config->webhook_generation,
            ])->save();
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
     * @param  list<array<string, mixed>>  $publishedGroups
     * @param  array<string, mixed>  $progress
     * @param  array<string, mixed>  $options
     */
    private function finalizeSuccess(
        Post $post,
        array $publishedGroups,
        array $progress,
        array $options,
        ?string $leaseId,
    ): bool {
        return DB::transaction(function () use ($post, $publishedGroups, $progress, $options, $leaseId): bool {
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
                || ($leaseId !== null
                    && ($locked->publish_claimed_at === null
                        || $locked->publish_claimed_at->isBefore(now()->subSeconds(PublishPostJob::LEASE_SECONDS))))
            ) {
                return false;
            }

            $locked->loadMissing('workspace');
            $latestConfig = PostsyncerConfig::fromWorkspace($locked->workspace);
            $latestGroups = $this->planner->plan($locked, $latestConfig, $options);
            $latestPlan = $this->planMetadata($latestConfig, $latestGroups, $options);

            if ($latestPlan['hash'] !== ($progress['plan_hash'] ?? null)
                || $latestPlan['groups'] !== ($progress['planned_groups'] ?? null)
            ) {
                throw new PostsyncerException(
                    'The post changed while the PostSyncer publish was running. '
                    .'The external groups were not finalized in Content Machine.'
                );
            }

            $request = $this->lockTelegramRequest($locked, $options);
            if ($request !== null && $request->state !== TelegramPostRequest::APPROVED) {
                return false;
            }

            $locked->forceFill([
                'postsyncer' => ['groups' => $publishedGroups],
                'status' => $this->hasScheduledCompletedGroup($publishedGroups) ? 'scheduled' : 'posted',
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
     * Rebuild only the metadata needed to validate a stored payload snapshot.
     * The current planner is intentionally not used for this group because
     * this command exists to recover from a changed plan.
     *
     * @param  array<string, mixed>  $completed
     * @param  array<string, mixed>  $expectedPayload
     */
    private function publishGroupFromSnapshot(
        array $completed,
        array $expectedPayload,
    ): PublishGroup {
        $workspaceId = $expectedPayload['workspace_id'] ?? null;
        $platforms = $completed['platforms'] ?? null;
        $language = $completed['language'] ?? null;
        $scheduleType = $expectedPayload['schedule_type'] ?? null;

        if ((! is_int($workspaceId) && ! is_string($workspaceId))
            || (string) $workspaceId === ''
            || ! is_string($language)
            || trim($language) === ''
            || ! is_array($platforms)
            || $platforms === []
            || ! is_string($scheduleType)) {
            throw new PostsyncerException(
                'The stored PostSyncer payload is not sufficient for plan-drift recovery.'
            );
        }

        $publishNow = $scheduleType === 'publish_now';
        if (! $publishNow && $scheduleType !== 'schedule') {
            throw new PostsyncerException(
                'The stored PostSyncer payload has an invalid schedule type.'
            );
        }

        $when = null;
        if (! $publishNow) {
            $scheduleFor = $expectedPayload['schedule_for'] ?? null;
            $date = is_array($scheduleFor) ? ($scheduleFor['date'] ?? null) : null;
            $time = is_array($scheduleFor) ? ($scheduleFor['time'] ?? null) : null;
            $timezone = is_array($scheduleFor) ? ($scheduleFor['timezone'] ?? null) : null;

            if (! is_string($date) || ! is_string($time) || ! is_string($timezone)
                || trim($date) === '' || trim($time) === '' || trim($timezone) === '') {
                throw new PostsyncerException(
                    'The stored PostSyncer payload has no verifiable schedule.'
                );
            }

            try {
                $when = CarbonImmutable::parse($date.' '.$time, $timezone);
            } catch (Throwable) {
                throw new PostsyncerException(
                    'The stored PostSyncer payload has an invalid schedule.'
                );
            }
        }

        return new PublishGroup(
            language: $language,
            workspaceId: $workspaceId,
            platforms: array_values(array_map('strval', $platforms)),
            mediaUrls: [],
            captions: [],
            when: $when,
            publishNow: $publishNow,
        );
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
     * @param  array<int, mixed>  $mediaIds
     * @return list<string>
     */
    private function normalizeMediaIds(array $mediaIds): array
    {
        $normalized = [];

        foreach ($mediaIds as $mediaId) {
            if (! $this->hasNumericPostId($mediaId)) {
                throw new PostsyncerException('Every reconciled PostSyncer media id must be a positive integer.');
            }

            $normalized[] = (string) $mediaId;
        }

        if (count($normalized) !== count(array_unique($normalized))) {
            throw new PostsyncerException('Reconciled PostSyncer media ids must be unique.');
        }

        return $normalized;
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
     * A publish plan is a snapshot. Refuse to create another group if the post
     * was changed outside the normal mutation guard while this job ran.
     *
     * @param  array<string, mixed>  $options
     * @param  array{hash: string, groups: list<array{index: int, group_key: string}>, options: array<string, mixed>}  $plan
     */
    private function assertPlanUnchanged(
        Post $post,
        array $options,
        array $plan,
        string $runToken,
        ?string $leaseId = null,
    ): bool {
        $post->refresh();
        if (! $this->runTokenMatches($post->publish_progress, $runToken)
            || ($leaseId !== null && $post->publish_lease_id !== $leaseId)
            || ($leaseId !== null
                && ($post->publish_claimed_at === null
                    || $post->publish_claimed_at->isBefore(now()->subSeconds(PublishPostJob::LEASE_SECONDS))))
        ) {
            return false;
        }

        $post->loadMissing('workspace');
        $config = PostsyncerConfig::fromWorkspace($post->workspace);
        $currentPlan = $this->planMetadata(
            $config,
            $this->planner->plan($post, $config, $options),
            $options,
        );

        if ($currentPlan['hash'] !== $plan['hash']
            || $currentPlan['groups'] !== $plan['groups']) {
            throw new PostsyncerException(
                'The post changed while the PostSyncer publish was running. '
                .'Retry requires a new publish plan.'
            );
        }

        $post->refresh();

        return $this->runTokenMatches($post->publish_progress, $runToken)
            && ($leaseId === null || $post->publish_lease_id === $leaseId)
            && ($leaseId === null
                || ($post->publish_claimed_at !== null
                    && $post->publish_claimed_at->isAfter(now()->subSeconds(PublishPostJob::LEASE_SECONDS))));
    }

    /**
     * Persist a progress checkpoint only while this worker still owns the
     * current run. The row lock makes the token check and write indivisible
     * from a manual retry.
     *
     * @param  array<string, mixed>  $progress
     */
    private function saveProgressForRun(
        Post $post,
        array $progress,
        string $runToken,
        ?string $leaseId = null,
    ): bool {
        return DB::transaction(function () use ($post, $progress, $runToken, $leaseId): bool {
            $lockedPost = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedPost === null
                || (! $this->runTokenMatches($lockedPost->publish_progress, $runToken)
                    && ! ($lockedPost->publish_progress === null
                        && $lockedPost->publish_state === 'running'))
                || ($lockedPost->approval_state ?? 'approved') !== 'approved'
                || ($leaseId !== null && $lockedPost->publish_lease_id !== $leaseId)
                || ($leaseId !== null
                    && ($lockedPost->publish_claimed_at === null
                        || $lockedPost->publish_claimed_at->isBefore(now()->subSeconds(PublishPostJob::LEASE_SECONDS))))) {
                return false;
            }

            $request = $this->lockTelegramRequest($lockedPost, $progress['options'] ?? []);
            if ($request !== null && $request->state !== TelegramPostRequest::APPROVED) {
                return false;
            }

            $lockedPost->forceFill([
                'publish_progress' => $progress,
                'publish_claimed_at' => $leaseId === null ? $lockedPost->publish_claimed_at : now(),
            ])->save();

            return true;
        });
    }

    /**
     * @param  array<string, mixed>|null  $localProgress
     * @param  array<string, mixed>  $options
     * @return bool|null True/false is whether the failure has an uncertain
     *                   outcome; null means the worker no longer owns the run.
     */
    private function recordFailureForRun(
        Post $post,
        string $runToken,
        string $originalStatus,
        ?array $localProgress,
        Throwable $exception,
        ?string $leaseId = null,
        ?string $operationId = null,
        array $options = [],
    ): ?bool {
        return DB::transaction(function () use (
            $post,
            $runToken,
            $originalStatus,
            $localProgress,
            $exception,
            $leaseId,
            $operationId,
            $options,
        ): ?bool {
            $lockedPost = Post::query()
                ->whereKey($post->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedPost === null
                || (! $this->runTokenMatches($lockedPost->publish_progress, $runToken)
                    && ! ($lockedPost->publish_progress === null
                        && $lockedPost->publish_state === 'running'))) {
                return null;
            }

            $latestProgress = $lockedPost->publish_progress;
            if ($operationId !== null
                && (! is_array($latestProgress) || ($latestProgress['operation_id'] ?? null) !== $operationId)
            ) {
                return null;
            }

            if ($leaseId !== null && $lockedPost->publish_lease_id !== $leaseId) {
                return null;
            }

            if ($leaseId !== null
                && ($lockedPost->publish_claimed_at === null
                    || $lockedPost->publish_claimed_at->isBefore(now()->subSeconds(PublishPostJob::LEASE_SECONDS)))) {
                return null;
            }

            if ($lockedPost->publish_state === 'succeeded'
                && $this->hasExistingPublicGroup($lockedPost)) {
                return null;
            }

            $failedProgress = $this->failureProgress(
                $localProgress,
                is_array($latestProgress) ? $latestProgress : null,
            );

            // A definitive create rejection is safe to retry, but the media
            // registration is not. Keep its ids so the next attempt creates
            // with the already-registered media instead of uploading again.
            if ($this->hasDefinitiveResponse($exception) && is_array($failedProgress)) {
                $current = $failedProgress['current'] ?? null;

                if (is_array($current)
                    && in_array(($current['phase'] ?? null), ['creating', 'retryable'], true)
                    && ($current['media_ids'] ?? []) !== []) {
                    $current['phase'] = 'retryable';
                    $failedProgress['current'] = $current;
                } elseif (! is_array($current)
                    || ($current['phase'] ?? null) !== 'uploading'
                    || ($exception instanceof PostsyncerException && $exception->safeToRetry)) {
                    $failedProgress['current'] = null;
                }
            }

            $unknownOutcome = false;
            if (is_array($failedProgress)) {
                $unknownOutcome = $this->hasUnknownCurrent($failedProgress)
                    || ($failedProgress['state'] ?? null) === 'uncertain';
                $failedProgress['state'] = $unknownOutcome ? 'uncertain' : 'failed';
            }

            $error = $exception->getMessage();
            if ($unknownOutcome) {
                $phase = is_array($failedProgress['current'] ?? null)
                    ? ($failedProgress['current']['phase'] ?? null)
                    : null;
                $message = $phase === 'uploading'
                    ? 'PostSyncer media upload outcome is uncertain. Inspect and clean up PostSyncer media before retrying. '
                    : 'PostSyncer create outcome is uncertain. Reconcile PostSyncer before retrying. ';
                $error = $message.$error;
            }

            $lockedPost->forceFill([
                'status' => $originalStatus,
                'publish_state' => 'failed',
                'publish_error' => $error,
                'publish_progress' => $failedProgress,
                'publish_claimed_at' => null,
                'publish_lease_id' => null,
            ])->save();

            $this->updateTelegramPostRequests($lockedPost, TelegramPostRequest::FAILED, $error, $options);

            return $unknownOutcome;
        });
    }

    /**
     * @param  array<string, mixed>|null  $progress
     */
    private function hasRunToken(?array $progress): bool
    {
        return is_string($progress['run_token'] ?? null)
            && trim($progress['run_token']) !== '';
    }

    /**
     * @param  array<string, mixed>|null  $progress
     */
    private function runTokenMatches(?array $progress, string $runToken): bool
    {
        return $this->hasRunToken($progress)
            && hash_equals((string) $progress['run_token'], $runToken);
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array{hash: string, groups: list<array{index: int, group_key: string}>, options: array<string, mixed>}  $plan
     * @param  list<PublishGroup>  $groups
     * @return array<string, mixed>|null
     */
    private function prepareProgress(
        Post $post,
        ?array $existing,
        array $plan,
        string $runToken,
        ?string $publishError,
        PostsyncerConfig $config,
        array $groups,
        ?string $leaseId = null,
    ): ?array {
        if ($existing === null) {
            $progress = [
                'version' => 1,
                'operation_id' => (string) Str::uuid(),
                'run_token' => $runToken,
                'options' => $plan['options'],
                'plan_hash' => $plan['hash'],
                'planned_groups' => $plan['groups'],
                'completed_groups' => [],
                'current' => null,
                'state' => 'running',
            ];

            if (! $this->saveProgressForRun($post, $progress, $runToken, $leaseId)) {
                return null;
            }

            return $progress;
        }

        $this->assertProgressShape($existing);

        $legacyAccountFailure = LegacyPublishProgress::isMissingAccountFailure(
            $publishError,
            $existing,
        );

        if ($legacyAccountFailure) {
            $existing = $this->repairLegacyAccountProgress($existing, $plan, $config, $groups);
        }

        if (! $legacyAccountFailure
            && ($this->hasUnknownCurrent($existing)
                || ($existing['state'] ?? null) === 'uncertain')) {
            throw new PostsyncerException(
                'A PostSyncer media upload or create has an unknown outcome. Resolve it before retrying.'
            );
        }

        $storedOptions = $existing['options'] ?? null;
        if (! is_array($storedOptions)
            || $this->canonicalJson($this->normalizeOptions($storedOptions))
                !== $this->canonicalJson($plan['options'])) {
            throw new PostsyncerException(
                'The publish options changed since this operation started. '
                .'Reconcile the existing PostSyncer posts before retrying.'
            );
        }

        $storedHash = $existing['plan_hash'] ?? null;
        $storedGroups = $existing['planned_groups'] ?? null;

        if ($storedHash === null) {
            if ($storedGroups !== []
                || $this->completedGroups($existing) !== []
                || ($existing['current'] ?? null) !== null) {
                throw new PostsyncerException(
                    'PostSyncer publish progress has no plan metadata. Reconcile it before retrying.'
                );
            }

            $existing['plan_hash'] = $plan['hash'];
            $existing['planned_groups'] = $plan['groups'];
        } elseif (! is_string($storedHash)
            || $storedHash !== $plan['hash']
            || $storedGroups !== $plan['groups']) {
            if ($this->completedGroups($existing) === []
                && ($existing['current'] ?? null) === null) {
                $existing['plan_hash'] = $plan['hash'];
                $existing['planned_groups'] = $plan['groups'];
            } else {
                throw new PostsyncerException(
                    'The publish plan changed since this operation started. '
                    .'Reconcile the existing PostSyncer posts before retrying.'
                );
            }
        }

        $this->assertCompletedGroupsBelongToPlan($existing, $plan['groups']);

        $existing['run_token'] = $runToken;
        $existing['options'] = $plan['options'];
        $existing['state'] = 'running';
        if (! $this->saveProgressForRun($post, $existing, $runToken, $leaseId)) {
            return null;
        }

        return $existing;
    }

    /**
     * Move a legacy pre-create account failure onto the current plan while
     * retaining its registered media ids. Completed groups must still match
     * the new plan before normal retry validation can continue.
     *
     * @param  array<string, mixed>  $progress
     * @param  array{hash: string, groups: list<array{index: int, group_key: string}>, options: array<string, mixed>}  $plan
     * @param  list<PublishGroup>  $groups
     * @return array<string, mixed>
     */
    private function repairLegacyAccountProgress(
        array $progress,
        array $plan,
        PostsyncerConfig $config,
        array $groups,
    ): array {
        $current = $progress['current'] ?? null;
        $index = is_array($current) ? ($current['index'] ?? null) : null;
        $planned = is_int($index) ? ($plan['groups'][$index] ?? null) : null;
        $stored = is_int($index) ? ($progress['planned_groups'][$index] ?? null) : null;
        $group = is_int($index) ? ($groups[$index] ?? null) : null;
        $mediaUrls = is_array($current) ? ($current['media_urls'] ?? null) : null;

        if (! is_array($current)
            || ! is_int($index)
            || ! is_array($planned)
            || ! is_array($stored)
            || ($stored['group_key'] ?? null) !== ($current['group_key'] ?? null)
            || ! $group instanceof PublishGroup
            || ! is_array($mediaUrls)
            || ! array_is_list($mediaUrls)
            || count(array_filter(
                $mediaUrls,
                static fn (mixed $url): bool => is_string($url),
            )) !== count($mediaUrls)
            || $this->canonicalMediaUrls($mediaUrls) !== $this->canonicalMediaUrls($group->mediaUrls)
            || ! $this->legacyGroupKeyMatches($config, $group, (string) $current['group_key'])) {
            throw new PostsyncerException(
                'This post has a legacy account-mapping checkpoint that cannot be repaired safely.'
            );
        }

        $current['phase'] = 'retryable';
        $current['group_key'] = $planned['group_key'];
        $current['idempotency_key'] = $this->idempotencyKey(
            (string) $progress['operation_id'],
            $index,
            $planned['group_key'],
        );
        $progress['current'] = $current;
        $progress['plan_hash'] = $plan['hash'];
        $progress['planned_groups'] = $plan['groups'];
        $progress['state'] = 'running';

        return $progress;
    }

    /**
     * The legacy key must match the current group with only account mappings
     * changing from null to their configured values.
     */
    private function legacyGroupKeyMatches(
        PostsyncerConfig $config,
        PublishGroup $group,
        string $legacyGroupKey,
    ): bool {
        $platforms = $group->platforms;
        $variants = 1 << count($platforms);

        for ($mask = 0; $mask < $variants; $mask++) {
            $accountOverrides = [];

            foreach ($platforms as $offset => $platform) {
                if (($mask & (1 << $offset)) !== 0) {
                    $accountOverrides[$platform] = null;
                }
            }

            if ($this->groupKey($config, $group, $accountOverrides) === $legacyGroupKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $urls
     * @return list<string>
     */
    private function canonicalMediaUrls(array $urls): array
    {
        $canonical = [];

        foreach ($urls as $url) {
            $canonical[] = $this->stableMediaUrl((string) $url);
        }

        return $canonical;
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
            || ! is_array($completedGroups)) {
            throw new PostsyncerException(
                'PostSyncer publish progress is invalid. Reconcile the post before retrying.'
            );
        }

        if (array_key_exists('run_token', $progress)
            && (! is_string($progress['run_token']) || trim($progress['run_token']) === '')) {
            throw new PostsyncerException(
                'PostSyncer publish progress is invalid. Reconcile the post before retrying.'
            );
        }

        foreach ($plannedGroups as $planned) {
            if (! is_array($planned)
                || ! is_int($planned['index'] ?? null)
                || ! is_string($planned['group_key'] ?? null)
                || trim($planned['group_key']) === '') {
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
                || (array_key_exists('expected_payload', $completed)
                    && ! is_array($completed['expected_payload']))) {
                throw new PostsyncerException(
                    'PostSyncer publish progress is invalid. Reconcile the post before retrying.'
                );
            }
        }

        $current = $progress['current'] ?? null;
        if ($current !== null
            && (! is_array($current)
                || ! is_int($current['index'] ?? null)
                || ! is_string($current['group_key'] ?? null)
                || ! is_string($current['idempotency_key'] ?? null)
                || trim($current['idempotency_key']) === ''
                || ! in_array(($current['phase'] ?? null), ['uploading', 'creating', 'retryable'], true)
                || ! is_array($current['media_ids'] ?? null)
                || (array_key_exists('media_urls', $current)
                    && ! is_array($current['media_urls']))
                || (array_key_exists('expected_payload', $current)
                    && ! is_array($current['expected_payload'])))) {
            throw new PostsyncerException(
                'PostSyncer publish progress is invalid. Reconcile the post before retrying.'
            );
        }

        if (is_array($current) && ($current['phase'] ?? null) === 'uploading'
            && (! is_array($current['media_urls'] ?? null) || $current['media_urls'] === [])) {
            throw new PostsyncerException(
                'PostSyncer publish progress is invalid. Reconcile the post before retrying.'
            );
        }

        if (array_key_exists('supplemental_groups', $progress)
            && ! is_array($progress['supplemental_groups'])) {
            throw new PostsyncerException(
                'PostSyncer publish progress has invalid supplemental groups.'
            );
        }

        foreach ($this->supplementalGroups($progress) as $supplemental) {
            if (! $this->hasExistingPostId($supplemental['post_id'] ?? null)
                || ! is_string($supplemental['status'] ?? null)
                || ! is_string($supplemental['language'] ?? null)
                || ! is_array($supplemental['platforms'] ?? null)
                || $supplemental['platforms'] === []) {
                throw new PostsyncerException(
                    'PostSyncer publish progress has invalid supplemental groups.'
                );
            }
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
     * @return list<array<string, mixed>>
     */
    private function supplementalGroups(array $progress): array
    {
        $groups = $progress['supplemental_groups'] ?? [];

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
                || $planned['group_key'] !== $key) {
                throw new PostsyncerException(
                    'Existing PostSyncer progress does not match this publish plan. '
                    .'Reconcile it before retrying.'
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
                && ($group['group_key'] ?? null) === $groupKey) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @param  array<string, mixed>  $replacement
     * @return list<array<string, mixed>>
     */
    private function upsertCompletedGroup(array $groups, array $replacement): array
    {
        $upserted = false;
        $deduplicated = [];

        foreach ($groups as $group) {
            if (($group['index'] ?? null) === ($replacement['index'] ?? null)
                && ($group['group_key'] ?? null) === ($replacement['group_key'] ?? null)) {
                if (! $upserted) {
                    $deduplicated[] = $replacement;
                    $upserted = true;
                }

                continue;
            }

            $deduplicated[] = $group;
        }

        if (! $upserted) {
            $deduplicated[] = $replacement;
        }

        return $deduplicated;
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function allPlannedGroupsCompleted(array $progress): bool
    {
        $plannedGroups = $progress['planned_groups'] ?? null;

        if (! is_array($plannedGroups) || ($progress['current'] ?? null) !== null) {
            return false;
        }

        $completedGroups = $this->completedGroups($progress);

        foreach ($plannedGroups as $planned) {
            if (! is_array($planned)
                || ! is_int($planned['index'] ?? null)
                || ! is_string($planned['group_key'] ?? null)
                || $this->completedGroup(
                    $completedGroups,
                    $planned['index'],
                    $planned['group_key'],
                ) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return media ids cached after a definitive create rejection.
     *
     * @param  array<string, mixed>  $progress
     * @return list<int|string>|null
     */
    private function reconciledMediaIdsForGroup(
        array $progress,
        int $index,
        string $groupKey,
    ): ?array {
        $current = $progress['current'] ?? null;

        if (! is_array($current)
            || ($current['phase'] ?? null) !== 'retryable'
            || ($current['index'] ?? null) !== $index
            || ($current['group_key'] ?? null) !== $groupKey
            || ! is_array($current['media_ids'] ?? null)) {
            return null;
        }

        return array_values($current['media_ids']);
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

        $telegramRequestId = $this->telegramRequestId($options);
        if ($telegramRequestId !== null) {
            $normalized['telegram_request_id'] = $telegramRequestId;
        }

        ksort($normalized);

        return $normalized;
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
        if (! array_key_exists('current', $progress) || $progress['current'] === null) {
            return false;
        }

        return ! is_array($progress['current'])
            || ($progress['current']['phase'] ?? null) !== 'retryable';
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     */
    private function hasScheduledCompletedGroup(array $groups): bool
    {
        foreach ($groups as $group) {
            if (strtoupper((string) ($group['status'] ?? '')) === 'SCHEDULED'
                || filled($group['scheduled_at'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function idempotencyKey(string $operationId, int $index, string $groupKey): string
    {
        return hash('sha256', $operationId.'|'.$index.'|'.$groupKey);
    }

    /**
     * @param  array<string, mixed>  $accountOverrides
     */
    private function groupKey(
        PostsyncerConfig $config,
        PublishGroup $group,
        array $accountOverrides = [],
    ): string {
        $langConfig = $config->language($group->language);
        $platformAccounts = $langConfig['platforms'];
        $accounts = [];
        $platforms = $group->platforms;
        sort($platforms);

        foreach ($platforms as $platform) {
            $platformConfig = is_array($platformAccounts[$platform] ?? null)
                ? $platformAccounts[$platform]
                : [];
            $accounts[$platform] = array_key_exists($platform, $accountOverrides)
                ? $accountOverrides[$platform]
                : ($platformConfig['account_id'] ?? null);
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

    private function hasExistingPostId(mixed $postId): bool
    {
        if (is_int($postId) || is_float($postId)) {
            return $postId > 0;
        }

        return is_string($postId) && trim($postId) !== '';
    }

    private function hasNumericPostId(mixed $postId): bool
    {
        return is_int($postId)
            ? $postId > 0
            : is_string($postId) && ctype_digit($postId) && (int) $postId > 0;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>|null  $expectedPayload
     * @return array<string, mixed>
     */
    private function formatProgressGroup(
        PublishGroup $group,
        array $result,
        int $index,
        string $groupKey,
        bool $operatorConfirmedFailed = false,
        ?array $expectedPayload = null,
    ): array {
        $postId = $result['id'] ?? null;

        if (! $this->hasNumericPostId($postId)) {
            throw new PostsyncerException('PostSyncer returned no post id after creating a group.');
        }

        $remoteStatus = strtoupper((string) ($result['status'] ?? ''));
        $status = $operatorConfirmedFailed
            && in_array($remoteStatus, ['FAILED', 'PARTIALLY_FAILED'], true)
            ? $remoteStatus
            : $this->assertPublishableStatus(
                $result['status'] ?? null,
                $group,
                'PostSyncer create response',
            );
        $scheduledAt = $result['scheduled_at'] ?? null;

        if (! $group->publishNow) {
            if (! is_string($scheduledAt)
                || trim($scheduledAt) === ''
                || $group->when === null) {
                throw new PostsyncerException(
                    'PostSyncer create response has no verifiable schedule.'
                );
            }

            try {
                $remoteWhen = CarbonImmutable::parse($scheduledAt, $group->when->timezone);
            } catch (Throwable) {
                throw new PostsyncerException(
                    'PostSyncer create response has an invalid schedule.'
                );
            }

            if ($remoteWhen->format('Y-m-d H:i') !== $group->when->format('Y-m-d H:i')) {
                throw new PostsyncerException(
                    'PostSyncer create response does not match the requested schedule.'
                );
            }
        }

        $formatted = [
            'index' => $index,
            'group_key' => $groupKey,
            'post_id' => (string) $postId,
            'status' => $status,
            'scheduled_at' => is_string($scheduledAt) ? $scheduledAt : null,
            'platforms' => $group->platforms,
            'language' => $group->language,
        ];

        if ($operatorConfirmedFailed) {
            $formatted['remote_status'] = $remoteStatus;
            $formatted['operator_confirmed'] = true;
        }

        if ($expectedPayload !== null) {
            $formatted['expected_payload'] = $expectedPayload;
        }

        return $formatted;
    }

    private function assertPublishableStatus(
        mixed $status,
        PublishGroup $group,
        string $context,
    ): string {
        if (! is_string($status) || trim($status) === '') {
            throw new PostsyncerException("{$context} has no valid lifecycle status.");
        }

        $normalized = strtoupper(trim($status));
        $acceptable = $group->publishNow
            ? ['PUBLISHED', 'IN_QUEUE', 'PENDING', 'QUEUED']
            : ['SCHEDULED', 'PUBLISHED', 'IN_QUEUE', 'PENDING', 'QUEUED'];

        if (! in_array($normalized, $acceptable, true)) {
            throw new PostsyncerException("{$context} is not in a publishable state.");
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $progressGroup
     * @return array{post_id: string, status: string, scheduled_at: string|null, platforms: list<string>, language: string}
     */
    private function publicGroup(array $progressGroup): array
    {
        $public = [
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

        if (isset($progressGroup['remote_status'])) {
            $public['remote_status'] = (string) $progressGroup['remote_status'];
        }

        if (($progressGroup['operator_confirmed'] ?? false) === true) {
            $public['operator_confirmed'] = true;
        }

        return $public;
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function publicRemoteGroup(
        array $remote,
        PublishGroup $group,
        int|string $postId,
    ): array {
        return [
            'post_id' => (string) $postId,
            'status' => strtoupper((string) ($remote['status'] ?? '')),
            'scheduled_at' => isset($remote['scheduled_at'])
                ? (string) $remote['scheduled_at']
                : null,
            'platforms' => $group->platforms,
            'language' => $group->language,
        ];
    }
}
