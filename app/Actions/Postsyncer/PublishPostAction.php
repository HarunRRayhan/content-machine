<?php

namespace App\Actions\Postsyncer;

use App\Models\Post;
use App\Support\Postsyncer\PostPublishPlanner;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PublishGroup;
use Throwable;

class PublishPostAction
{
    public function __construct(
        private readonly PostPublishPlanner $planner,
    ) {}

    /**
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options
     */
    public function handle(Post $post, array $options): void
    {
        $originalStatus = $post->status;

        $post->update([
            'publish_state' => 'running',
            'publish_error' => null,
        ]);

        try {
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

            $publishedGroups = [];
            $anyScheduled = false;

            foreach ($groups as $group) {
                $mediaIds = [];

                if ($group->mediaUrls !== []) {
                    $mediaIds = $client->uploadFromUrls($group->workspaceId, $group->mediaUrls);

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
                }

                $result = $client->createPost($this->buildPostBody($config, $group, $mediaIds));
                $publishedGroups[] = $this->formatGroupResult($group, $result);

                if (! $group->publishNow) {
                    $anyScheduled = true;
                }
            }

            $post->update([
                'postsyncer' => ['groups' => $publishedGroups],
                'status' => $anyScheduled ? 'scheduled' : 'posted',
                'publish_state' => 'succeeded',
                'publish_error' => null,
            ]);
        } catch (Throwable $e) {
            $post->update([
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

    private function assertFirstPublish(Post $post): void
    {
        $groups = $post->postsyncer['groups'] ?? null;

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
                    'This post already has PostSyncer posts. Republish is not supported yet.'
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
        return [
            'post_id' => (string) ($result['id'] ?? ''),
            'status' => strtoupper((string) ($result['status'] ?? '')),
            'scheduled_at' => isset($result['scheduled_at']) ? (string) $result['scheduled_at'] : null,
            'platforms' => $group->platforms,
            'language' => $group->language,
        ];
    }
}
