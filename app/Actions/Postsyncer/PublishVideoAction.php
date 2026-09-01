<?php

namespace App\Actions\Postsyncer;

use App\Models\Video;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PublishGroup;
use App\Support\Postsyncer\VideoPublishPlanner;
use Carbon\CarbonImmutable;
use Throwable;

class PublishVideoAction
{
    public function __construct(
        private readonly VideoPublishPlanner $planner,
    ) {}

    /**
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options
     */
    public function handle(Video $video, array $options): void
    {
        $originalStatus = $video->status;

        $video->update([
            'publish_state' => 'running',
            'publish_error' => null,
        ]);

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

            $publishedGroups = [];
            $anyScheduled = false;

            foreach ($groups as $group) {
                $mediaIds = $group->mediaUrls !== []
                    ? $client->uploadFromUrls($group->workspaceId, $group->mediaUrls)
                    : [];

                if ($group->mediaUrls !== [] && $mediaIds === []) {
                    throw new PostsyncerException(
                        'PostSyncer returned no media ids after uploading the video. '
                        .'Refusing to publish this group without video media.'
                    );
                }

                $result = $client->createPost($this->buildPostBody($config, $group, $mediaIds));
                $publishedGroups[] = $this->formatGroupResult($group, $result);

                if (! $group->publishNow) {
                    $anyScheduled = true;
                }
            }

            $video->update([
                'postsyncer' => ['groups' => $publishedGroups],
                'status' => $anyScheduled ? 'scheduled' : 'posted',
                'publish_state' => 'succeeded',
                'publish_error' => null,
            ]);
        } catch (Throwable $e) {
            $video->update([
                'status' => $originalStatus,
                'publish_state' => 'failed',
                'publish_error' => $e->getMessage(),
            ]);
        }
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
            $settings = $this->platformSettings($platform, $caption);

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
        $groups = $video->postsyncer['groups'] ?? null;

        if (! is_array($groups)) {
            return;
        }

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $postId = $group['post_id'] ?? null;

            if ($this->hasExistingPostId($postId)) {
                throw new PostsyncerException(
                    'This video already has PostSyncer posts. Republish is not supported yet.'
                );
            }
        }
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
     * @return array{post_id: string, status: string, scheduled_at: string|null, platforms: list<string>, language: string}
     */
    private function formatGroupResult(PublishGroup $group, array $result): array
    {
        $postId = $result['id'] ?? null;

        if (! $this->hasNumericPostId($postId)) {
            throw new PostsyncerException('PostSyncer returned no post id after creating a group.');
        }

        $status = $this->assertPublishableStatus($result['status'] ?? null, $group);
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

        return [
            'post_id' => (string) $postId,
            'status' => $status,
            'scheduled_at' => is_string($scheduledAt) ? $scheduledAt : null,
            'platforms' => $group->platforms,
            'language' => $group->language,
        ];
    }

    private function assertPublishableStatus(mixed $status, PublishGroup $group): string
    {
        if (! is_string($status) || trim($status) === '') {
            throw new PostsyncerException('PostSyncer create response has no valid lifecycle status.');
        }

        $normalized = strtoupper(trim($status));
        $acceptable = $group->publishNow
            ? ['PUBLISHED', 'IN_QUEUE', 'PENDING', 'QUEUED']
            : ['SCHEDULED', 'PUBLISHED', 'IN_QUEUE', 'PENDING', 'QUEUED'];

        if (! in_array($normalized, $acceptable, true)) {
            throw new PostsyncerException('PostSyncer create response is not in a publishable state.');
        }

        return $normalized;
    }

    private function hasNumericPostId(mixed $postId): bool
    {
        return is_int($postId)
            ? $postId > 0
            : is_string($postId) && ctype_digit($postId) && (int) $postId > 0;
    }
}
