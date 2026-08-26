<?php

namespace Tests\Unit\Support\Postsyncer;

use App\Models\Post;
use App\Models\Workspace;
use App\Support\Postsyncer\MediaUrlResolver;
use App\Support\Postsyncer\PostPublishPlanner;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PublishGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostPublishPlannerTest extends TestCase
{
    use RefreshDatabase;

    private PostPublishPlanner $planner;

    /**
     * @return array<string, mixed>
     */
    private function samplePostTypes(): array
    {
        return [
            'platforms' => [
                'facebook' => ['text' => 'on', 'photo' => 'on'],
                'instagram' => ['photo' => 'on'],
                'twitter' => ['text' => 'on', 'photo' => 'off'],
                'threads' => ['text' => 'on', 'photo' => 'ask'],
            ],
            'overrides' => [
                'english' => [
                    'threads' => ['photo' => 'ask'],
                ],
                'bangla' => [
                    'twitter' => ['text' => 'off', 'photo' => 'off'],
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

        $this->planner = new PostPublishPlanner(new MediaUrlResolver);
    }

    public function test_plans_single_bangla_photo_group(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'bn',
            'platforms' => ['facebook', 'instagram', 'twitter'],
            'captions' => [
                'main' => [
                    'facebook' => ['caption' => 'FB caption'],
                    'instagram' => ['caption' => 'IG caption'],
                ],
            ],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);

        $groups = $this->planner->plan($post, $config, [
            'confirm_ask' => false,
        ]);

        $this->assertCount(1, $groups);
        $group = $groups[0];
        $this->assertInstanceOf(PublishGroup::class, $group);
        $this->assertSame('bangla', $group->language);
        $this->assertSame('15211', $group->workspaceId);
        $this->assertSame(['facebook', 'instagram'], $group->platforms);
        $this->assertSame(['https://drive.google.com/file/d/abc/view'], $group->mediaUrls);
        $this->assertSame([
            'facebook' => 'FB caption',
            'instagram' => 'IG caption',
        ], $group->captions);
        $this->assertTrue($group->publishNow);
        $this->assertNull($group->when);
    }

    public function test_english_post_uses_english_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Hello'],
        ]);

        $group = $this->planner->plan($post, $config, ['confirm_ask' => false])[0];

        $this->assertSame('english', $group->language);
        $this->assertSame('853', $group->workspaceId);
        $this->assertSame(['facebook'], $group->platforms);
        $this->assertSame(['facebook' => 'Hello'], $group->captions);
        $this->assertSame([], $group->mediaUrls);
    }

    public function test_platforms_option_overrides_post_platforms(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'bn',
            'platforms' => ['facebook', 'instagram'],
            'captions' => ['facebook' => 'Only FB'],
        ]);

        $group = $this->planner->plan($post, $config, [
            'platforms' => ['facebook'],
            'confirm_ask' => false,
        ])[0];

        $this->assertSame(['facebook'], $group->platforms);
    }

    public function test_scheduled_when_sets_publish_now_false(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Later'],
        ]);

        $when = '2026-08-26 09:12:00';
        $group = $this->planner->plan($post, $config, [
            'when' => $when,
            'confirm_ask' => false,
        ])[0];

        $this->assertFalse($group->publishNow);
        $this->assertInstanceOf(CarbonImmutable::class, $group->when);
        $this->assertSame($when, $group->when->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Dhaka', $group->when->timezoneName);
    }

    public function test_naive_when_is_parsed_in_workspace_timezone(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Later'],
        ]);

        $group = $this->planner->plan($post, $config, [
            'when' => '2026-08-26T09:12',
            'confirm_ask' => false,
        ])[0];

        $this->assertFalse($group->publishNow);
        $this->assertSame('Asia/Dhaka', $group->when?->timezoneName);
        $this->assertSame('2026-08-26 09:12:00', $group->when?->format('Y-m-d H:i:s'));
    }

    public function test_when_with_offset_keeps_that_offset(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Later'],
        ]);

        $group = $this->planner->plan($post, $config, [
            'when' => '2026-08-26T09:12:00+06:00',
            'confirm_ask' => false,
        ])[0];

        $this->assertSame('+06:00', $group->when?->timezoneName);
        $this->assertSame('2026-08-26 09:12:00', $group->when?->format('Y-m-d H:i:s'));
    }

    public function test_throws_when_ask_platform_selected_without_confirm(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['threads'],
            'captions' => ['threads' => 'Thread text'],
            'image_drive_urls' => ['https://drive.google.com/file/d/photo/view'],
        ]);

        $this->expectException(PostsyncerException::class);
        $this->expectExceptionMessage('threads');

        $this->planner->plan($post, $config, ['confirm_ask' => false]);
    }

    public function test_needs_confirm_ask_when_ask_platform_in_publish_set(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['threads'],
            'captions' => ['threads' => 'Thread text'],
            'image_drive_urls' => ['https://drive.google.com/file/d/photo/view'],
        ]);

        $this->assertTrue($this->planner->needsConfirmAsk($post, $config));
    }

    public function test_needs_confirm_ask_false_when_no_ask_platforms(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'bn',
            'platforms' => ['facebook', 'instagram'],
            'captions' => [
                'main' => [
                    'facebook' => ['caption' => 'FB caption'],
                    'instagram' => ['caption' => 'IG caption'],
                ],
            ],
            'image_drive_urls' => ['https://drive.google.com/file/d/abc/view'],
        ]);

        $this->assertFalse($this->planner->needsConfirmAsk($post, $config));
    }

    public function test_includes_ask_platform_when_confirm_ask_true(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['threads'],
            'captions' => ['threads' => 'Thread text'],
            'image_drive_urls' => ['https://drive.google.com/file/d/photo/view'],
        ]);

        $group = $this->planner->plan($post, $config, ['confirm_ask' => true])[0];

        $this->assertSame(['threads'], $group->platforms);
    }

    public function test_skips_unsupported_platforms(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'bn',
            'platforms' => ['facebook', 'instagram'],
            'captions' => [
                'facebook' => 'FB',
                'instagram' => 'IG',
            ],
        ]);

        $group = $this->planner->plan($post, $config, ['confirm_ask' => false])[0];

        $this->assertSame(['facebook'], $group->platforms);
    }

    public function test_bilingual_post_emits_separate_language_groups(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);
        $captions = json_decode(
            file_get_contents(base_path('tests/Fixtures/postsyncer/p48_captions.json')),
            true,
        );

        $post = Post::factory()->for($workspace)->create([
            'language' => 'both',
            'platforms' => ['facebook', 'instagram', 'twitter', 'threads', 'bluesky', 'tiktok'],
            'captions' => $captions,
        ]);

        $groups = $this->planner->plan($post, $config, ['confirm_ask' => true]);

        $langs = array_map(fn (PublishGroup $g) => $g->language, $groups);
        $this->assertContains('bangla', $langs);
        $this->assertContains('english', $langs);
        $this->assertGreaterThanOrEqual(2, count($groups));

        $banglaWorkspace = collect($groups)->first(fn (PublishGroup $g) => $g->language === 'bangla');
        $englishWorkspace = collect($groups)->first(fn (PublishGroup $g) => $g->language === 'english');
        $this->assertSame('15211', $banglaWorkspace->workspaceId);
        $this->assertSame('853', $englishWorkspace->workspaceId);
    }

    public function test_twitter_thread_gets_own_group_threads_stays_separate(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['twitter', 'threads'],
            'captions' => [
                'English' => [
                    'twitter' => [
                        'caption' => 'Tweet one',
                        'thread' => ['Tweet two', 'Tweet three'],
                    ],
                    'threads' => [
                        'caption' => 'Threads caption',
                        'images' => ['https://drive.google.com/file/d/th/view'],
                    ],
                ],
            ],
        ]);

        $groups = $this->planner->plan($post, $config, ['confirm_ask' => true]);

        $twitterGroup = collect($groups)->first(
            fn (PublishGroup $g) => $g->platforms === ['twitter'],
        );
        $threadsGroup = collect($groups)->first(
            fn (PublishGroup $g) => in_array('threads', $g->platforms, true),
        );

        $this->assertNotNull($twitterGroup);
        $this->assertSame(['Tweet one', 'Tweet two', 'Tweet three'], $twitterGroup->threadTweets);
        $this->assertNotNull($threadsGroup);
        $this->assertNotContains('twitter', $threadsGroup->platforms);
        $this->assertSame(['threads' => 'Threads caption'], $threadsGroup->captions);
    }

    public function test_different_image_sets_split_into_separate_groups(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'bn',
            'platforms' => ['facebook', 'instagram'],
            'captions' => [
                'main' => [
                    'facebook' => [
                        'caption' => 'FB',
                        'images' => ['https://drive.google.com/file/d/a/view'],
                    ],
                    'instagram' => [
                        'caption' => 'IG',
                        'images' => ['https://drive.google.com/file/d/b/view'],
                    ],
                ],
            ],
        ]);

        $groups = $this->planner->plan($post, $config, ['confirm_ask' => false]);

        $this->assertCount(2, $groups);
        $mediaSets = array_map(fn (PublishGroup $g) => $g->mediaUrls, $groups);
        $this->assertContains(['https://drive.google.com/file/d/a/view'], $mediaSets);
        $this->assertContains(['https://drive.google.com/file/d/b/view'], $mediaSets);
    }

    public function test_p48_fixture_uses_per_language_covers(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);
        $captions = json_decode(
            file_get_contents(base_path('tests/Fixtures/postsyncer/p48_captions.json')),
            true,
        );

        $post = Post::factory()->for($workspace)->create([
            'language' => 'both',
            'platforms' => ['facebook'],
            'captions' => $captions,
        ]);

        $groups = $this->planner->plan($post, $config, ['confirm_ask' => false]);

        $bangla = collect($groups)->first(fn (PublishGroup $g) => $g->language === 'bangla');
        $english = collect($groups)->first(fn (PublishGroup $g) => $g->language === 'english');

        $this->assertSame(['https://drive.google.com/file/d/p48-bn-cover/view'], $bangla->mediaUrls);
        $this->assertSame(['https://drive.google.com/file/d/p48-en-cover/view'], $english->mediaUrls);
    }
}
