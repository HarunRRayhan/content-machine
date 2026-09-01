<?php

namespace App\Actions\Postsyncer;

use App\Models\Video;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PublishGroup;
use App\Support\Postsyncer\VideoPublishPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PublishVideoAction
{
    public function __construct(
        private readonly VideoPublishPlanner $planner,
    ) {}

    /**
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options
     */
    public function handle(Video $video, array $options, ?string $runToken = null): void
    {
        $video->refresh();
        $originalStatus = $video->status;
        $options = $this->normalizeOptions($options);
        $progress = $video->publish_progress;

        // Direct callers may resume the current checkpoint. Legacy queued
        // deliveries are fenced by PublishVideoJob before reaching here.
        $runToken ??= is_string($progress['run_token'] ?? null)
            ? $progress['run_token']
            : (string) Str::uuid();

        // A duplicate delivery after finalization must not create another
        // PostSyncer post.
        if ($video->publish_state === 'succeeded' && $this->hasExistingPublicGroup($video)) {
            return;
        }

        if (! $this->startRun($video, $runToken)) {
            return;
        }

        $video->refresh();
        $progress = $video->publish_progress;

        try {
            $video->loadMissing('workspace');
            $this->assertFirstPublish($video);
            $config = PostsyncerConfig::fromWorkspace($video->workspace);
            $client = new PostsyncerClient($config);
            $groups = $this->planner->plan($video, $config, $options);

            if ($groups === []) {
                throw new PostsyncerException(
                    'No PostSyncer publish groups could be planned for this video.'
                );
            }

            $plan = $this->planMetadata($config, $groups, $options);
            $progress = $this->prepareProgress($video, $progress, $plan, $runToken);
            if ($progress === null) {
                return;
            }
            $completedGroups = $this->completedGroups($progress);

            foreach ($groups as $index => $group) {
                if (! $this->assertPlanUnchanged($video, $options, $plan, $runToken)) {
                    return;
                }
                $groupKey = $this->groupKey($config, $group);

                if ($this->completedGroup($completedGroups, $index, $groupKey) !== null) {
                    continue;
                }

                $mediaIds = $group->mediaUrls !== []
                    ? $client->uploadFromUrls($group->workspaceId, $group->mediaUrls)
                    : [];

                if ($group->mediaUrls !== [] && $mediaIds === []) {
                    throw new PostsyncerException(
                        'PostSyncer returned no media ids after uploading the video. '
                        .'Refusing to publish this group without video media.'
                    );
                }

                $video->refresh();
                if (! $this->runTokenMatches($video->publish_progress, $runToken)) {
                    return;
                }

                $body = $this->buildPostBody($config, $group, $mediaIds);
                $progress['state'] = 'running';
                $progress['current'] = [
                    'index' => $index,
                    'group_key' => $groupKey,
                    'phase' => 'creating',
                    'idempotency_key' => $this->idempotencyKey(
                        (string) $progress['operation_id'],
                        $index,
                        $groupKey,
                    ),
                    'media_ids' => $mediaIds,
                ];
                if (! $this->saveProgressForRun($video, $progress, $runToken)) {
                    return;
                }

                $result = $client->createPost($body);
                $video->refresh();
                if (! $this->runTokenMatches($video->publish_progress, $runToken)) {
                    return;
                }
                $verified = $this->verifyCreatedPost(
                    $client,
                    $result,
                    $config,
                    $group,
                    $mediaIds,
                );
                $video->refresh();
                if (! $this->runTokenMatches($video->publish_progress, $runToken)) {
                    return;
                }
                $completedGroups[] = $this->formatProgressGroup(
                    $group,
                    $verified,
                    $index,
                    $groupKey,
                );
                usort(
                    $completedGroups,
                    fn (array $left, array $right): int => ((int) $left['index']) <=> ((int) $right['index']),
                );

                // Checkpoint every external create before moving to the next group.
                $progress['completed_groups'] = $completedGroups;
                $progress['current'] = null;
                if (! $this->saveProgressForRun($video, $progress, $runToken)) {
                    return;
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
            DB::transaction(function () use (
                $video,
                $options,
                $plan,
                $publishedGroups,
                $groups,
                $progress,
                $runToken,
            ): void {
                $lockedVideo = Video::query()
                    ->whereKey($video->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                if (! $this->runTokenMatches($lockedVideo->publish_progress, $runToken)) {
                    return;
                }

                if (! $lockedVideo->isPublishInProgress()) {
                    throw new PostsyncerException(
                        'The video publish state changed before finalization. Retry the publish.'
                    );
                }

                $lockedVideo->loadMissing('workspace');
                $latestConfig = PostsyncerConfig::fromWorkspace($lockedVideo->workspace);
                $latestGroups = $this->planner->plan($lockedVideo, $latestConfig, $options);
                $latestPlan = $this->planMetadata($latestConfig, $latestGroups, $options);

                if ($latestPlan['hash'] !== $plan['hash']
                    || $latestPlan['groups'] !== $plan['groups']) {
                    throw new PostsyncerException(
                        'The video changed while the PostSyncer publish was running. '
                        .'The external groups were not finalized in Content Machine.'
                    );
                }

                $lockedVideo->forceFill([
                    'postsyncer' => ['groups' => $publishedGroups],
                    'status' => $this->hasScheduledGroup($groups) ? 'scheduled' : 'posted',
                    'publish_state' => 'succeeded',
                    'publish_error' => null,
                    'publish_progress' => $progress,
                ])->save();
            });
        } catch (Throwable $e) {
            $unknownOutcome = $this->recordFailureForRun(
                $video,
                $runToken,
                $originalStatus,
                is_array($progress) ? $progress : null,
                $e,
            );

            if ($unknownOutcome === null) {
                return;
            }

            // Deterministic errors stay in Content Machine. Safe transient
            // failures must reach the queue worker for retry.
            if (! $unknownOutcome
                && ! ($e instanceof PostsyncerException && ! $e->retryable)
                && ! ($e instanceof \InvalidArgumentException)) {
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
    public function reconcile(
        Video $video,
        int|string $postsyncerPostId,
        bool $confirmFailed = false,
    ): void {
        if (! $this->hasNumericPostId($postsyncerPostId)) {
            throw new PostsyncerException('A PostSyncer post id is required for reconciliation.');
        }

        $video->refresh();
        $progress = $video->publish_progress;

        if (! is_array($progress)) {
            throw new PostsyncerException('This video has no PostSyncer progress to reconcile.');
        }

        $this->assertProgressShape($progress);

        if (($progress['state'] ?? null) !== 'uncertain'
            || ! is_array($progress['current'] ?? null)) {
            throw new PostsyncerException(
                'This video does not have an uncertain PostSyncer create to reconcile.'
            );
        }

        $current = $progress['current'];
        $video->loadMissing('workspace');
        $config = PostsyncerConfig::fromWorkspace($video->workspace);
        $options = $progress['options'];
        $groups = $this->planner->plan($video, $config, $options);
        $index = $current['index'];
        $group = $groups[$index] ?? null;

        if (! $group instanceof PublishGroup
            || $this->groupKey($config, $group) !== $current['group_key']) {
            throw new PostsyncerException(
                'The PostSyncer reconciliation group no longer matches the video publish plan.'
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
            $confirmFailed,
        );

        DB::transaction(function () use (
            $video,
            $progress,
            $current,
            $remote,
            $index,
            $postsyncerPostId,
            $confirmFailed,
        ): void {
            $lockedVideo = Video::query()
                ->whereKey($video->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $latestProgress = $lockedVideo->publish_progress;

            if (! is_array($latestProgress)
                || ($latestProgress['operation_id'] ?? null) !== ($progress['operation_id'] ?? null)
                || ($latestProgress['state'] ?? null) !== 'uncertain'
                || ($latestProgress['current']['index'] ?? null) !== $current['index']
                || ($latestProgress['current']['group_key'] ?? null) !== $current['group_key']) {
                throw new PostsyncerException(
                    'The PostSyncer progress changed while video reconciliation was running.'
                );
            }

            $lockedVideo->loadMissing('workspace');
            $latestConfig = PostsyncerConfig::fromWorkspace($lockedVideo->workspace);
            $latestGroups = $this->planner->plan($lockedVideo, $latestConfig, $latestProgress['options']);
            $latestGroup = $latestGroups[$current['index']] ?? null;

            if (! $latestGroup instanceof PublishGroup
                || $this->groupKey($latestConfig, $latestGroup) !== $current['group_key']) {
                throw new PostsyncerException(
                    'The video or PostSyncer settings changed while reconciliation was running.'
                );
            }

            $this->assertReconciledPost(
                $remote,
                $latestConfig,
                $latestGroup,
                $current['media_ids'],
                $postsyncerPostId,
                $confirmFailed,
            );

            $completedGroups = $this->completedGroups($latestProgress);
            $completedGroups[] = $this->formatProgressGroup(
                $latestGroup,
                $remote,
                $index,
                $current['group_key'],
                $confirmFailed && strtoupper((string) ($remote['status'] ?? '')) === 'FAILED',
            );
            usort(
                $completedGroups,
                fn (array $left, array $right): int => ((int) $left['index']) <=> ((int) $right['index']),
            );

            $latestProgress['completed_groups'] = $completedGroups;
            $latestProgress['current'] = null;
            $latestProgress['state'] = 'failed';
            $lockedVideo->forceFill([
                'publish_state' => 'failed',
                'publish_error' => 'PostSyncer post '.(string) $postsyncerPostId
                    .' reconciled. Retry to continue the publish.',
                'publish_progress' => $latestProgress,
            ])->save();
        });

        $video->refresh();
    }

    /**
     * @param  list<int|string>  $mediaIds
     * @return array<string, mixed>
     */
    private function buildPostBody(PostsyncerConfig $config, PublishGroup $group, array $mediaIds): array
    {
        $langConfig = $config->language($group->language);
        $platformAccounts = $langConfig['platforms'];

        $videoMediaId = $mediaIds[0] ?? null;
        $coverMediaId = $mediaIds[1] ?? null;

        $content = [
            'text' => $this->defaultCaption($group),
            'media' => $videoMediaId !== null ? [$videoMediaId] : [],
        ];

        if ($coverMediaId !== null) {
            $content['cover_image'] = ['thumbnail' => $coverMediaId];
        }

        $body = [
            'workspace_id' => $group->workspaceId,
            'content' => [$content],
            'accounts' => $this->buildAccounts($platformAccounts, $group),
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
    private function buildAccounts(array $platformAccounts, PublishGroup $group): array
    {
        $accounts = [];

        foreach ($group->platforms as $platform) {
            $platformConfig = is_array($platformAccounts[$platform] ?? null)
                ? $platformAccounts[$platform]
                : [];
            $accountId = $platformConfig['account_id'] ?? null;

            if ($accountId === null || $accountId === '') {
                throw new PostsyncerException("No account id mapped for platform {$platform}.");
            }

            $caption = $group->captions[$platform] ?? '';
            $accounts[] = [
                'id' => $accountId,
                'settings' => $this->platformSettings($platform, $caption),
            ];
        }

        return $accounts;
    }

    /**
     * @return array<string, mixed>
     */
    private function platformSettings(string $platform, string $caption): array
    {
        if ($caption === '') {
            return match ($platform) {
                'facebook', 'instagram' => ['post_type' => 'REELS'],
                'youtube' => [
                    'video_type' => 'short',
                    'privacyStatus' => 'public',
                ],
                default => [],
            };
        }

        return match ($platform) {
            'facebook', 'instagram' => [
                'post_type' => 'REELS',
                'caption' => $caption,
            ],
            'twitter' => ['text' => $caption],
            'threads', 'bluesky' => ['title' => $caption],
            'tiktok' => ['description' => $caption],
            'youtube' => [
                'video_type' => 'short',
                'description' => $caption,
                'privacyStatus' => 'public',
            ],
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

    private function assertFirstPublish(Video $video): void
    {
        if ($this->hasExistingPublicGroup($video)) {
            throw new PostsyncerException(
                'This video already has PostSyncer posts. Republish is not supported yet.'
            );
        }
    }

    private function hasExistingPublicGroup(Video $video): bool
    {
        $groups = $video->postsyncer['groups'] ?? null;

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

    private function hasDefinitiveResponse(Throwable $exception): bool
    {
        return $exception instanceof PostsyncerException
            && $exception->responseReceived
            && ! $exception->outcomeUnknown;
    }

    /**
     * Verify a created video through the canonical PostSyncer resource. The
     * create has already happened, so an incomplete or failed lookup is never
     * safe to retry automatically.
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
                'PostSyncer accepted the video create but returned no verifiable post id.',
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
                $remote = $this->normalizePostResponse($client->getPost((string) $postId));
                $this->assertReconciledPost($remote, $config, $group, $mediaIds, $postId);

                return $remote;
            } catch (Throwable $exception) {
                $lastException = $exception;

                if (! $this->shouldRetryCanonicalLookup($remote, $exception)) {
                    break;
                }
            }
        }

        $message = 'PostSyncer accepted video post '.(string) $postId
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
     * payload is populated. Retry only transient/asynchronous lookup states;
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
        $localCurrent = $local['current'] ?? null;
        $latestCurrent = $latest['current'] ?? null;

        if (is_array($localCurrent) && $latestCurrent === null) {
            $merged['current'] = $localCurrent;
        }

        $completed = $this->completedGroups($latest);
        foreach ($this->completedGroups($local) as $localGroup) {
            if ($this->completedGroup(
                $completed,
                (int) ($localGroup['index'] ?? -1),
                (string) ($localGroup['group_key'] ?? ''),
            ) === null) {
                $completed[] = $localGroup;
            }
        }

        usort(
            $completed,
            fn (array $left, array $right): int => ((int) ($left['index'] ?? 0))
                <=> ((int) ($right['index'] ?? 0)),
        );
        $merged['completed_groups'] = $completed;

        return $merged;
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
        bool $allowFailed = false,
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

        $expected = $this->buildPostBody($config, $group, $mediaIds);
        $remoteContent = $remote['content'] ?? null;

        if (! is_array($remoteContent) || count($remoteContent) !== count($expected['content'])) {
            throw new PostsyncerException(
                'The supplied PostSyncer post does not match the current video publish group.'
            );
        }

        foreach ($expected['content'] as $index => $expectedItem) {
            $remoteItem = $remoteContent[$index] ?? null;
            $expectedMedia = is_array($expectedItem['media'] ?? null)
                ? $expectedItem['media']
                : [];

            if (! is_array($remoteItem)) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post does not match the current video publish group.'
                );
            }

            $remoteMedia = $remoteItem['media'] ?? [];
            if (($remoteItem['text'] ?? null) !== ($expectedItem['text'] ?? null)
                || ! is_array($remoteMedia)
                || count($remoteMedia) !== count($expectedMedia)
                || $this->responseMediaIds($remoteMedia)
                    !== array_map('strval', $expectedMedia)) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post does not match the current video publish group.'
                );
            }

            $expectedCover = $expectedItem['cover_image'] ?? null;
            $remoteCover = $remoteItem['cover_image'] ?? null;
            if (($expectedCover === null) !== ($remoteCover === null)
                || ($expectedCover !== null
                    && (! is_array($remoteCover)
                        || (string) ($remoteCover['thumbnail'] ?? '')
                            !== (string) ($expectedCover['thumbnail'] ?? '')))) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post does not match the current video publish group.'
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
                'The supplied PostSyncer post does not match the current video publish group.'
            );
        }
        sort($actualPlatforms);
        sort($expectedPlatforms);

        if ($actualPlatforms !== $expectedPlatforms) {
            throw new PostsyncerException(
                'The supplied PostSyncer post does not match the current video publish group.'
            );
        }

        $expectedAccounts = $this->buildAccounts(
            $config->language($group->language)['platforms'],
            $group,
        );
        foreach ($group->platforms as $index => $platform) {
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
                    'The supplied PostSyncer post does not match the current video publish group.'
                );
            }

            $expectedIndex = array_search($platform, $group->platforms, true);
            $expectedAccountId = is_int($expectedIndex)
                ? ($expectedAccounts[$expectedIndex]['id'] ?? null)
                : null;
            $remoteAccountId = $remotePlatform['account_id'] ?? data_get($remotePlatform, 'account.id');
            if (! $this->hasExistingPostId($remoteAccountId)
                || (string) $remoteAccountId !== (string) $expectedAccountId) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post targets a different account.'
                );
            }

            $expectedSettings = is_int($expectedIndex)
                ? ($expectedAccounts[$expectedIndex]['settings'] ?? [])
                : [];
            $remoteSettings = $remotePlatform['settings'] ?? null;
            if ($expectedSettings !== []) {
                if (! is_array($remoteSettings)) {
                    throw new PostsyncerException(
                        'The supplied PostSyncer post has no platform settings to verify.'
                    );
                }

                foreach ($expectedSettings as $setting => $value) {
                    if (! array_key_exists($setting, $remoteSettings)
                        || $remoteSettings[$setting] !== $value) {
                        throw new PostsyncerException(
                            'The supplied PostSyncer post does not match the current platform settings.'
                        );
                    }
                }
            }
        }

        $remoteStatus = strtoupper((string) ($remote['status'] ?? ''));
        if (! ($allowFailed && $remoteStatus === 'FAILED')) {
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
     * A publish plan is a snapshot. Refuse to create another group if the
     * video or its PostSyncer settings changed while this job ran.
     *
     * @param  array<string, mixed>  $options
     * @param  array{hash: string, groups: list<array{index: int, group_key: string}>, options: array<string, mixed>}  $plan
     */
    private function assertPlanUnchanged(
        Video $video,
        array $options,
        array $plan,
        string $runToken,
    ): bool {
        $video->refresh();
        if (! $this->runTokenMatches($video->publish_progress, $runToken)) {
            return false;
        }

        $video->loadMissing('workspace');
        $config = PostsyncerConfig::fromWorkspace($video->workspace);
        $currentPlan = $this->planMetadata(
            $config,
            $this->planner->plan($video, $config, $options),
            $options,
        );

        if ($currentPlan['hash'] !== $plan['hash']
            || $currentPlan['groups'] !== $plan['groups']) {
            throw new PostsyncerException(
                'The video changed while the PostSyncer publish was running. '
                .'Retry requires a new publish plan.'
            );
        }

        $video->refresh();

        return $this->runTokenMatches($video->publish_progress, $runToken);
    }

    private function startRun(Video $video, string $runToken): bool
    {
        return DB::transaction(function () use ($video, $runToken): bool {
            $lockedVideo = Video::query()
                ->whereKey($video->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedVideo === null) {
                return false;
            }

            if ($lockedVideo->publish_state === 'succeeded') {
                return false;
            }

            $progress = $lockedVideo->publish_progress;
            if ($this->hasRunToken($progress)
                && ! $this->runTokenMatches($progress, $runToken)) {
                return false;
            }

            if (is_array($progress)) {
                $progress['run_token'] = $runToken;
            }

            $lockedVideo->forceFill([
                'publish_state' => 'running',
                'publish_error' => null,
                'publish_progress' => $progress,
            ])->save();

            return true;
        });
    }

    /**
     * Persist a progress checkpoint only while this worker still owns the
     * current run. The row lock makes the token check and write indivisible
     * from a manual retry.
     *
     * @param  array<string, mixed>  $progress
     */
    private function saveProgressForRun(Video $video, array $progress, string $runToken): bool
    {
        return DB::transaction(function () use ($video, $progress, $runToken): bool {
            $lockedVideo = Video::query()
                ->whereKey($video->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedVideo === null
                || (! $this->runTokenMatches($lockedVideo->publish_progress, $runToken)
                    && ! ($lockedVideo->publish_progress === null
                        && $lockedVideo->publish_state === 'running'))) {
                return false;
            }

            $lockedVideo->update(['publish_progress' => $progress]);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>|null  $localProgress
     * @return bool|null True/false is whether the failure has an uncertain
     *                   outcome; null means the worker no longer owns the run.
     */
    private function recordFailureForRun(
        Video $video,
        string $runToken,
        string $originalStatus,
        ?array $localProgress,
        Throwable $exception,
    ): ?bool {
        return DB::transaction(function () use (
            $video,
            $runToken,
            $originalStatus,
            $localProgress,
            $exception,
        ): ?bool {
            $lockedVideo = Video::query()
                ->whereKey($video->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedVideo === null
                || (! $this->runTokenMatches($lockedVideo->publish_progress, $runToken)
                    && ! ($lockedVideo->publish_progress === null
                        && $lockedVideo->publish_state === 'running'))) {
                return null;
            }

            if ($lockedVideo->publish_state === 'succeeded'
                && $this->hasExistingPublicGroup($lockedVideo)) {
                return null;
            }

            $latestProgress = $lockedVideo->publish_progress;
            $failedProgress = $this->failureProgress(
                $localProgress,
                is_array($latestProgress) ? $latestProgress : null,
            );

            // A definitive response proves this create was rejected. The
            // pre-create checkpoint can be safely retried.
            if ($this->hasDefinitiveResponse($exception) && is_array($failedProgress)) {
                $failedProgress['current'] = null;
            }

            $unknownOutcome = false;
            if (is_array($failedProgress)) {
                $unknownOutcome = $this->hasUnknownCurrent($failedProgress)
                    || ($failedProgress['state'] ?? null) === 'uncertain';
                $failedProgress['state'] = $unknownOutcome ? 'uncertain' : 'failed';
            }

            $error = $exception->getMessage();
            if ($unknownOutcome) {
                $error = 'PostSyncer create outcome is uncertain. Reconcile PostSyncer before retrying. '
                    .$error;
            }

            $lockedVideo->forceFill([
                'status' => $originalStatus,
                'publish_state' => 'failed',
                'publish_error' => $error,
                'publish_progress' => $failedProgress,
            ])->save();

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
     * @return array<string, mixed>
     */
    private function prepareProgress(
        Video $video,
        ?array $existing,
        array $plan,
        string $runToken,
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

            if (! $this->saveProgressForRun($video, $progress, $runToken)) {
                return null;
            }

            return $progress;
        }

        $this->assertProgressShape($existing);

        if ($this->hasUnknownCurrent($existing)
            || ($existing['state'] ?? null) === 'uncertain') {
            throw new PostsyncerException(
                'A PostSyncer create has an unknown outcome. Reconcile it before retrying.'
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
            if ($storedGroups !== [] || $this->completedGroups($existing) !== []) {
                throw new PostsyncerException(
                    'PostSyncer publish progress has no plan metadata. Reconcile it before retrying.'
                );
            }

            $existing['plan_hash'] = $plan['hash'];
            $existing['planned_groups'] = $plan['groups'];
        } elseif (! is_string($storedHash)
            || $storedHash !== $plan['hash']
            || $storedGroups !== $plan['groups']) {
            throw new PostsyncerException(
                'The publish plan changed since this operation started. '
                .'Reconcile the existing PostSyncer posts before retrying.'
            );
        }

        $this->assertCompletedGroupsBelongToPlan($existing, $plan['groups']);

        $existing['run_token'] = $runToken;
        $existing['options'] = $plan['options'];
        $existing['state'] = 'running';
        $existing['current'] = null;
        if (! $this->saveProgressForRun($video, $existing, $runToken)) {
            return null;
        }

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
            || ! is_array($completedGroups)) {
            throw new PostsyncerException(
                'PostSyncer publish progress is invalid. Reconcile the video before retrying.'
            );
        }

        if (array_key_exists('run_token', $progress)
            && (! is_string($progress['run_token']) || trim($progress['run_token']) === '')) {
            throw new PostsyncerException(
                'PostSyncer publish progress is invalid. Reconcile the video before retrying.'
            );
        }

        foreach ($plannedGroups as $planned) {
            if (! is_array($planned)
                || ! is_int($planned['index'] ?? null)
                || ! is_string($planned['group_key'] ?? null)
                || trim($planned['group_key']) === '') {
                throw new PostsyncerException(
                    'PostSyncer publish progress is invalid. Reconcile the video before retrying.'
                );
            }
        }

        foreach ($completedGroups as $completed) {
            if (! is_array($completed)
                || ! is_int($completed['index'] ?? null)
                || ! is_string($completed['group_key'] ?? null)
                || ! $this->hasExistingPostId($completed['post_id'] ?? null)) {
                throw new PostsyncerException(
                    'PostSyncer publish progress is invalid. Reconcile the video before retrying.'
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
                || ($current['phase'] ?? null) !== 'creating'
                || ! is_array($current['media_ids'] ?? null))) {
            throw new PostsyncerException(
                'PostSyncer publish progress is invalid. Reconcile the video before retrying.'
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
                || $planned['group_key'] !== $key) {
                throw new PostsyncerException(
                    'Existing PostSyncer progress does not match this video publish plan. '
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
        return array_key_exists('current', $progress) && $progress['current'] !== null;
    }

    private function idempotencyKey(string $operationId, int $index, string $groupKey): string
    {
        return hash('sha256', $operationId.'|'.$index.'|'.$groupKey);
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
     * @return array{index: int, group_key: string, post_id: string, status: string, scheduled_at: string|null, platforms: list<string>, language: string}
     */
    private function formatProgressGroup(
        PublishGroup $group,
        array $result,
        int $index,
        string $groupKey,
        bool $operatorConfirmedFailed = false,
    ): array {
        $postId = $result['id'] ?? null;

        if (! $this->hasNumericPostId($postId)) {
            throw new PostsyncerException('PostSyncer returned no post id after creating a group.');
        }

        $remoteStatus = strtoupper((string) ($result['status'] ?? ''));
        $status = $operatorConfirmedFailed && $remoteStatus === 'FAILED'
            ? 'FAILED'
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

        return $formatted;
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
}
