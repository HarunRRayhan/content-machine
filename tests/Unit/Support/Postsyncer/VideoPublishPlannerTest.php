<?php

namespace Tests\Unit\Support\Postsyncer;

use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\MediaUrlResolver;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PublishGroup;
use App\Support\Postsyncer\VideoPublishPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoPublishPlannerTest extends TestCase
{
    use RefreshDatabase;

    private VideoPublishPlanner $planner;

    /**
     * @return array<string, mixed>
     */
    private function samplePostTypes(): array
    {
        return [
            'platforms' => [
                'facebook' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
                'instagram' => ['photo' => 'on', 'reel' => 'on'],
                'tiktok' => ['photo' => 'on', 'reel' => 'on'],
                'youtube' => ['reel' => 'on'],
                'twitter' => ['text' => 'on', 'thread' => 'on'],
            ],
            'overrides' => [
                'english' => [
                    'tiktok' => ['reel' => 'ask'],
                ],
                'bangla' => [
                    'twitter' => ['text' => 'off', 'photo' => 'off', 'thread' => 'off'],
                ],
            ],
        ];
    }

    private function configFor(Workspace $workspace): PostsyncerConfig
    {
        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
            'post_types' => $this->samplePostTypes(),
        ]);
        $workspace->refresh();

        return PostsyncerConfig::fromWorkspace($workspace);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = new VideoPublishPlanner(new MediaUrlResolver);
    }

    public function test_plans_single_bangla_reel_group_with_video_and_cover(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $video = Video::factory()->for($workspace)->create([
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => 'https://drive.google.com/file/d/cover/view',
            'captions' => [
                'main' => [
                    'facebook' => ['caption' => 'FB reel caption'],
                    'instagram' => ['caption' => 'IG reel caption'],
                    'tiktok' => ['title' => 'Hook', 'caption' => 'TT body'],
                ],
            ],
        ]);

        $groups = $this->planner->plan($video, $config, [
            'platforms' => ['facebook', 'instagram', 'tiktok', 'twitter'],
            'confirm_ask' => false,
        ]);

        $this->assertCount(1, $groups);
        $group = $groups[0];
        $this->assertInstanceOf(PublishGroup::class, $group);
        $this->assertSame('bangla', $group->language);
        $this->assertSame('15211', $group->workspaceId);
        $this->assertSame(['facebook', 'instagram', 'tiktok'], $group->platforms);
        $this->assertSame([
            'https://drive.google.com/file/d/video/view',
            'https://drive.google.com/file/d/cover/view',
        ], $group->mediaUrls);
        $this->assertSame([
            'facebook' => 'FB reel caption',
            'instagram' => 'IG reel caption',
            'tiktok' => 'TT body',
        ], $group->captions);
        $this->assertTrue($group->publishNow);
        $this->assertNull($group->when);
    }

    public function test_english_video_uses_english_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $video = Video::factory()->for($workspace)->create([
            'language' => 'en',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => [
                'facebook' => 'Hello reel',
            ],
        ]);

        $group = $this->planner->plan($video, $config, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ])[0];

        $this->assertSame('english', $group->language);
        $this->assertSame('853', $group->workspaceId);
        $this->assertSame(['facebook'], $group->platforms);
        $this->assertSame(['facebook' => 'Hello reel'], $group->captions);
        $this->assertSame(['https://drive.google.com/file/d/video/view'], $group->mediaUrls);
    }

    public function test_platforms_option_filters_selected_platforms(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $video = Video::factory()->for($workspace)->create([
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => [
                'facebook' => 'FB',
                'instagram' => 'IG',
            ],
        ]);

        $group = $this->planner->plan($video, $config, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ])[0];

        $this->assertSame(['facebook'], $group->platforms);
    }

    public function test_scheduled_when_sets_publish_now_false(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $video = Video::factory()->for($workspace)->create([
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Later'],
        ]);

        $when = '2026-08-26 09:12:00';
        $group = $this->planner->plan($video, $config, [
            'platforms' => ['facebook'],
            'when' => $when,
            'confirm_ask' => false,
        ])[0];

        $this->assertFalse($group->publishNow);
        $this->assertInstanceOf(CarbonImmutable::class, $group->when);
        $this->assertSame($when, $group->when->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Dhaka', $group->when->timezoneName);
    }

    public function test_no_platforms_in_options_plans_groups_from_captions(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $video = Video::factory()->for($workspace)->create([
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => [
                'main' => [
                    'facebook' => ['caption' => 'FB reel caption'],
                    'instagram' => ['caption' => 'IG reel caption'],
                    'tiktok' => ['caption' => 'TT body'],
                ],
            ],
        ]);

        $groups = $this->planner->plan($video, $config, [
            'confirm_ask' => false,
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(['facebook', 'instagram', 'tiktok'], $groups[0]->platforms);
        $this->assertSame([
            'facebook' => 'FB reel caption',
            'instagram' => 'IG reel caption',
            'tiktok' => 'TT body',
        ], $groups[0]->captions);
    }

    public function test_empty_platforms_array_plans_groups_from_captions(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $video = Video::factory()->for($workspace)->create([
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => [
                'facebook' => 'FB',
                'instagram' => 'IG',
            ],
        ]);

        $group = $this->planner->plan($video, $config, [
            'platforms' => [],
            'confirm_ask' => false,
        ])[0];

        $this->assertSame(['facebook', 'instagram'], $group->platforms);
    }

    public function test_naive_when_is_parsed_in_workspace_timezone(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);
        $config = $this->configFor($workspace);

        $video = Video::factory()->for($workspace)->create([
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Later'],
        ]);

        $group = $this->planner->plan($video, $config, [
            'when' => '2026-08-26T09:12',
            'confirm_ask' => false,
        ])[0];

        $this->assertFalse($group->publishNow);
        $this->assertSame('Asia/Dhaka', $group->when?->timezoneName);
        $this->assertSame('2026-08-26 09:12:00', $group->when?->format('Y-m-d H:i:s'));
    }

    public function test_when_with_offset_converts_to_workspace_timezone(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);
        $config = $this->configFor($workspace);

        $video = Video::factory()->for($workspace)->create([
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['facebook' => 'Later'],
        ]);

        $group = $this->planner->plan($video, $config, [
            'platforms' => ['facebook'],
            'when' => '2026-08-26T09:12:00+06:00',
            'confirm_ask' => false,
        ])[0];

        $this->assertSame('Asia/Dhaka', $group->when?->timezoneName);
        $this->assertSame('2026-08-26 09:12:00', $group->when?->format('Y-m-d H:i:s'));
    }

    public function test_needs_confirm_ask_uses_caption_keys_when_platforms_omitted(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $video = Video::factory()->for($workspace)->create([
            'language' => 'en',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['tiktok' => 'TT caption'],
        ]);

        $this->assertTrue($this->planner->needsConfirmAsk($video, $config));
    }

    public function test_throws_when_ask_platform_selected_without_confirm(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $video = Video::factory()->for($workspace)->create([
            'language' => 'en',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['tiktok' => 'TT caption'],
        ]);

        $this->expectException(PostsyncerException::class);
        $this->expectExceptionMessage('tiktok');

        $this->planner->plan($video, $config, [
            'platforms' => ['tiktok'],
            'confirm_ask' => false,
        ]);
    }

    public function test_includes_ask_platform_when_confirm_ask_true(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $video = Video::factory()->for($workspace)->create([
            'language' => 'en',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => ['tiktok' => 'TT caption'],
        ]);

        $group = $this->planner->plan($video, $config, [
            'platforms' => ['tiktok'],
            'confirm_ask' => true,
        ])[0];

        $this->assertSame(['tiktok'], $group->platforms);
    }

    public function test_skips_platforms_without_reel_support(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $video = Video::factory()->for($workspace)->create([
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'captions' => [
                'facebook' => 'FB',
                'twitter' => 'Should skip',
            ],
        ]);

        $group = $this->planner->plan($video, $config, [
            'platforms' => ['facebook', 'twitter'],
            'confirm_ask' => false,
        ])[0];

        $this->assertSame(['facebook'], $group->platforms);
    }

    public function test_omits_cover_from_media_urls_when_missing(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $video = Video::factory()->for($workspace)->create([
            'language' => 'bn',
            'video_drive_url' => 'https://drive.google.com/file/d/video/view',
            'cover_drive_url' => null,
            'captions' => ['facebook' => 'FB'],
        ]);

        $group = $this->planner->plan($video, $config, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ])[0];

        $this->assertSame(['https://drive.google.com/file/d/video/view'], $group->mediaUrls);
    }
}
