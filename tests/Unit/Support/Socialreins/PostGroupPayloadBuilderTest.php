<?php

namespace Tests\Unit\Support\Socialreins;

use App\Models\Workspace;
use App\Support\Postsyncer\PublishGroup;
use App\Support\Socialreins\PostGroupPayloadBuilder;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostGroupPayloadBuilderTest extends TestCase
{
    use RefreshDatabase;

    private const SET = '11111111-1111-4111-8111-111111111111';

    private function builder(?string $tiktok = 'SELF_ONLY'): PostGroupPayloadBuilder
    {
        $workspace = Workspace::factory()->create();
        SocialreinsConfig::write($workspace, [
            'tiktok_privacy_level' => $tiktok,
            'languages' => ['english' => ['set_id' => self::SET, 'token' => 't']],
        ]);

        return new PostGroupPayloadBuilder(SocialreinsConfig::fromWorkspace($workspace->fresh()));
    }

    private function group(array $platforms, array $captions, array $media = [], array $extra = []): PublishGroup
    {
        return new PublishGroup(
            language: 'english',
            workspaceId: self::SET,
            platforms: $platforms,
            mediaUrls: $media,
            captions: $captions,
            when: $extra['when'] ?? null,
            publishNow: ($extra['when'] ?? null) === null,
            threadTweets: $extra['threadTweets'] ?? null,
            firstComment: $extra['firstComment'] ?? null,
            mediaPerTweet: $extra['mediaPerTweet'] ?? 1,
        );
    }

    public function test_linkedin_keeps_its_pdf_while_others_get_images_and_x_is_named_x(): void
    {
        $payload = $this->builder()->build('english', [
            $this->group(['linkedin'], ['linkedin' => 'LI'], ['https://cm.test/doc.pdf'], ['firstComment' => 'Sources']),
            $this->group(['facebook', 'twitter'], ['facebook' => 'FB', 'twitter' => 'X'], ['https://cm.test/1.png', 'https://cm.test/2.png']),
        ], video: false);

        $this->assertSame(['linkedin', 'facebook', 'x'], $payload['platforms']);
        $this->assertSame(['https://cm.test/doc.pdf'], $payload['overrides']['linkedin']['media']);
        $this->assertSame('Sources', $payload['overrides']['linkedin']['first_comment']);
        $this->assertSame(['https://cm.test/1.png', 'https://cm.test/2.png'], $payload['overrides']['x']['media']);
        $this->assertArrayNotHasKey('first_comment', $payload['overrides']['facebook']);
        $this->assertSame(self::SET, $payload['social_set_id']);
    }

    public function test_thread_parts_carry_their_slice_of_the_images(): void
    {
        $payload = $this->builder()->build('english', [
            $this->group(['twitter'], ['twitter' => 'One'], ['a', 'b', 'c', 'd', 'e'], [
                'threadTweets' => ['One', '2/2'],
                'mediaPerTweet' => 4,
            ]),
        ], video: false);

        $x = $payload['overrides']['x'];
        $this->assertSame('One', $x['body']);
        $this->assertSame(['a', 'b', 'c', 'd'], $x['media']);
        $this->assertSame([['body' => '2/2', 'media' => ['e']]], $x['thread']);
    }

    public function test_video_media_is_the_video_and_the_cover_goes_only_where_brain_accepts_one(): void
    {
        $payload = $this->builder()->build('english', [
            $this->group(['instagram', 'linkedin', 'youtube'], ['instagram' => 'a', 'linkedin' => 'b', 'youtube' => 'c'], ['v.mp4', 'cover.jpg']),
        ], video: true);

        foreach (['instagram', 'linkedin', 'youtube'] as $platform) {
            $this->assertSame(['v.mp4'], $payload['overrides'][$platform]['media']);
        }
        $this->assertSame('cover.jpg', $payload['overrides']['instagram']['cover']);
        $this->assertSame('cover.jpg', $payload['overrides']['youtube']['cover']);
        $this->assertArrayNotHasKey('cover', $payload['overrides']['linkedin']);
    }

    public function test_tiktok_always_carries_the_workspace_privacy_and_is_refused_without_one(): void
    {
        $group = $this->group(['tiktok'], ['tiktok' => 'T'], ['v.mp4']);

        $payload = $this->builder('FOLLOWER_OF_CREATOR')->build('english', [$group], video: true);
        $this->assertSame(['privacy_level' => 'FOLLOWER_OF_CREATOR'], $payload['overrides']['tiktok']['tiktok']);

        $this->expectException(SocialreinsException::class);
        $this->builder(null)->build('english', [$group], video: true);
    }

    public function test_youtube_asks_for_a_public_upload_and_other_platforms_do_not(): void
    {
        $group = $this->group(['youtube', 'facebook'], ['youtube' => "Title\nDescription", 'facebook' => 'F'], ['v.mp4']);

        $payload = $this->builder()->build('english', [$group], video: true);
        $this->assertSame(['privacy' => 'public'], $payload['overrides']['youtube']['youtube']);
        $this->assertArrayNotHasKey('youtube', $payload['overrides']['facebook']);
    }

    public function test_bluesky_gets_at_most_four_images_and_other_platforms_all_of_them(): void
    {
        $payload = $this->builder()->build('english', [
            $this->group(['bluesky', 'facebook'], ['bluesky' => 'B', 'facebook' => 'F'], ['a', 'b', 'c', 'd', 'e']),
        ], video: false);

        $this->assertSame(['a', 'b', 'c', 'd'], $payload['overrides']['bluesky']['media']);
        $this->assertCount(5, $payload['overrides']['facebook']['media']);
    }

    public function test_a_youtube_first_line_that_fits_is_kept_and_a_long_one_is_cut_for_the_title(): void
    {
        $this->assertSame("Title\nMore", PostGroupPayloadBuilder::youtubeBody("Title\nMore"));
        $this->assertSame('a b', PostGroupPayloadBuilder::youtubeBody('<a> b'));
        $this->assertSame('Title', PostGroupPayloadBuilder::youtubeBody("\u{A0}\nTitle"));

        $long = str_repeat('é', 150);
        [$title, $description] = explode("\n", PostGroupPayloadBuilder::youtubeBody($long."\nTail"), 2);
        $this->assertSame(100, mb_strlen($title));
        $this->assertSame($long."\nTail", $description);

        [, $description] = explode("\n", PostGroupPayloadBuilder::youtubeBody("T\n".str_repeat('আ', 3000)), 2);
        $this->assertLessThanOrEqual(5000, strlen($description));
        $this->assertTrue(mb_check_encoding($description, 'UTF-8'));

        $payload = $this->builder()->build('english', [
            $this->group(['youtube', 'facebook'], ['youtube' => $long, 'facebook' => $long], ['https://cm.test/v.mp4']),
        ], video: true);
        $this->assertSame(100, mb_strlen(explode("\n", $payload['overrides']['youtube']['body'])[0]));
        $this->assertSame($long, $payload['overrides']['facebook']['body']);
    }

    public function test_schedule_is_utc_and_a_publish_now_time_is_frozen_into_the_body(): void
    {
        $when = CarbonImmutable::parse('2026-11-10 09:00', 'Asia/Dhaka');
        $payload = $this->builder()->build('english', [$this->group(['facebook'], ['facebook' => 'x'], [], ['when' => $when])], video: false);

        $this->assertSame('2026-11-10T03:00:00Z', $payload['scheduled_at']);
    }

    public function test_only_filters_platforms_and_returns_null_when_none_are_left(): void
    {
        $group = $this->group(['facebook', 'twitter'], ['facebook' => 'a', 'twitter' => 'b']);

        $this->assertSame(['x'], $this->builder()->build('english', [$group], false, ['twitter'])['platforms']);
        $this->assertSame('skip', $this->builder()->build('english', [$group], false)['on_invalid']);
        $this->assertNull($this->builder()->build('english', [$group], false, []));
    }
}
