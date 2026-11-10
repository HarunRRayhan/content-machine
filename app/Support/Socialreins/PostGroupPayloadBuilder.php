<?php

namespace App\Support\Socialreins;

use App\Support\Postsyncer\PublishGroup;
use Carbon\CarbonImmutable;

/**
 * Turns the planner's PublishGroups of one language into ONE brain `POST /post-groups` body:
 * the language's set, the platforms of every PublishGroup, and one override per platform
 * (caption, media, thread parts, first comment, cover, TikTok settings). A PublishGroup was
 * one PostSyncer call per media set; SocialReins takes the whole language in one call.
 */
final class PostGroupPayloadBuilder
{
    /** Platforms brain accepts a video cover on. */
    private const COVER_PLATFORMS = ['instagram', 'facebook', 'youtube'];

    public function __construct(private readonly SocialreinsConfig $config) {}

    /** CM names X "twitter"; brain names it "x". The rest match. */
    public static function brainPlatform(string $platform): string
    {
        return $platform === 'twitter' ? 'x' : $platform;
    }

    public static function cmPlatform(string $platform): string
    {
        return $platform === 'x' ? 'twitter' : $platform;
    }

    /**
     * @param  list<PublishGroup>  $groups  the planner's groups for one language
     * @param  bool  $video  media is [video, cover?] per group, not an image set
     * @param  list<string>|null  $only  CM platform names to keep (retry of failed platforms)
     * @return array<string, mixed>|null null when nothing is left to publish
     */
    public function build(string $language, array $groups, bool $video, ?array $only = null): ?array
    {
        $overrides = [];
        $when = null;

        foreach ($groups as $group) {
            $when ??= $group->when;

            foreach ($group->platforms as $platform) {
                if ($only !== null && ! in_array($platform, $only, true)) {
                    continue;
                }

                $overrides[self::brainPlatform($platform)] = $this->override($group, $platform, $video);
            }
        }

        if ($overrides === []) {
            return null;
        }

        $setId = $this->config->setId($language);
        if ($setId === null) {
            throw new SocialreinsException("No SocialReins set is configured for {$language}.");
        }

        return [
            'social_set_id' => $setId,
            'platforms' => array_keys($overrides),
            // A past time means now in brain, so a frozen "now" is safe to resend days later.
            'scheduled_at' => ($when ?? CarbonImmutable::now())->utc()->format('Y-m-d\TH:i:s\Z'),
            'overrides' => $overrides,
        ];
    }

    /**
     * The exact bytes sent and, on a retry, resent.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function override(PublishGroup $group, string $platform, bool $video): array
    {
        $override = [];
        $tweets = $group->threadTweets ?? [];
        $media = $video ? array_slice($group->mediaUrls, 0, 1) : $group->mediaUrls;

        if ($tweets !== []) {
            // Same split PostSyncer got: tweet N carries its slice of the images.
            $per = max(1, $group->mediaPerTweet);
            $override['body'] = $tweets[0];
            $override['media'] = array_slice($media, 0, $per);
            $override['thread'] = [];
            foreach (array_slice($tweets, 1, null, true) as $index => $text) {
                $override['thread'][] = ['body' => $text, 'media' => array_slice($media, $index * $per, $per)];
            }
        } else {
            $override['body'] = $group->captions[$platform] ?? '';
            $override['media'] = $media;
        }

        if ($video && count($group->mediaUrls) > 1 && in_array($platform, self::COVER_PLATFORMS, true)) {
            $override['cover'] = $group->mediaUrls[1];
        }

        if ($group->firstComment !== null && PublishGroup::supportsFirstComment($platform)) {
            $override['first_comment'] = $group->firstComment;
        }

        if ($platform === 'tiktok') {
            $privacy = $this->config->tiktokPrivacy();
            if ($privacy === null) {
                throw new SocialreinsException(
                    'Choose a TikTok visibility in Settings > SocialReins before publishing to TikTok. '
                    .'It is never defaulted to public.'
                );
            }
            $override['tiktok'] = ['privacy_level' => $privacy];
        }

        // SocialReins uploads to YouTube as private unless told otherwise; CM's videos go out public, as on PostSyncer.
        if ($platform === 'youtube') {
            $override['youtube'] = ['privacy' => 'public'];
        }

        return $override;
    }
}
