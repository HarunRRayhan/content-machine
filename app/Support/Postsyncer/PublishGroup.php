<?php

namespace App\Support\Postsyncer;

use Carbon\CarbonImmutable;

/**
 * One PostSyncer API call: a language workspace, platform set, media, and captions.
 */
final readonly class PublishGroup
{
    /**
     * Platforms that accept PostSyncer's `is_first_comment` content item.
     * Threads/Twitter/Bluesky/TikTok must never share a group that carries one.
     *
     * @var list<string>
     */
    public const FIRST_COMMENT_PLATFORMS = ['facebook', 'instagram', 'linkedin', 'youtube'];

    /**
     * @param  list<string>  $platforms
     * @param  list<string>  $mediaUrls
     * @param  array<string, string>  $captions
     * @param  list<string>|null  $threadTweets  Twitter-only: caption + thread segments
     */
    public function __construct(
        public string $language,
        public int|string $workspaceId,
        public array $platforms,
        public array $mediaUrls,
        public array $captions,
        public ?CarbonImmutable $when,
        public bool $publishNow,
        public ?array $threadTweets = null,
        public ?string $firstComment = null,
    ) {}

    public static function supportsFirstComment(string $platform): bool
    {
        return in_array($platform, self::FIRST_COMMENT_PLATFORMS, true);
    }
}
