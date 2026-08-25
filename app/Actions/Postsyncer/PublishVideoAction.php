<?php

namespace App\Actions\Postsyncer;

use App\Models\Video;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PublishGroup;
use App\Support\Postsyncer\VideoPublishPlanner;
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
            $config = PostsyncerConfig::fromWorkspace($video->workspace);
            $client = new PostsyncerClient($config);
            $groups = $this->planner->plan($video, $config, $options);

            $publishedGroups = [];
            $anyScheduled = false;

            foreach ($groups as $group) {
                $mediaIds = $group->mediaUrls !== []
                    ? $client->uploadFromUrls($group->workspaceId, $group->mediaUrls)
                    : [];

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
        $platformAccounts = is_array($langConfig['platforms'] ?? null) ? $langConfig['platforms'] : [];

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
                'timezone' => config('app.timezone', 'Asia/Dhaka'),
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

        $caption = reset($group->captions);

        return is_string($caption) ? $caption : '';
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{post_id: string, status: string, scheduled_at: string|null, platforms: list<string>, language: string}
     */
    private function formatGroupResult(PublishGroup $group, array $result): array
    {
        return [
            'post_id' => (string) ($result['id'] ?? ''),
            'status' => strtoupper((string) ($result['status'] ?? '')),
            'scheduled_at' => isset($result['scheduled_at']) ? (string) $result['scheduled_at'] : null,
            'platforms' => $group->platforms,
            'language' => $group->language,
        ];
    }
}
