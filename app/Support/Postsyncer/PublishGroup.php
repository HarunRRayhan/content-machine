<?php

namespace App\Support\Postsyncer;

use Carbon\CarbonImmutable;

/**
 * One PostSyncer API call: a language workspace, platform set, media, and captions.
 */
final readonly class PublishGroup
{
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
    ) {}
}
