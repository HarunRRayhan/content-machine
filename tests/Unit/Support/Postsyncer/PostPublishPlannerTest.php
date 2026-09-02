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
use InvalidArgumentException;
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
        $this->assertSame(['https://drive.usercontent.google.com/download?id=abc&export=download&confirm=t'], $group->mediaUrls);
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

    public function test_skips_platforms_that_are_explicitly_disabled(): void
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'instagram' => ['account_id' => '2', 'enabled' => false],
                    ],
                ],
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
            'post_types' => $this->samplePostTypes(),
        ]);
        $workspace->refresh();
        $config = PostsyncerConfig::fromWorkspace($workspace);

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

        $groups = $this->planner->plan($post, $config, [
            'confirm_ask' => false,
        ]);

        $this->assertCount(1, $groups);
        $this->assertSame(['facebook'], $groups[0]->platforms);
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

    public function test_invalid_when_is_reported_as_a_validation_error(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);
        $post = Post::factory()->for($workspace)->create([
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Later'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The publish time is invalid.');

        $this->planner->plan($post, $config, [
            'when' => 'not-a-date',
            'confirm_ask' => false,
        ]);
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

    public function test_when_with_offset_converts_to_workspace_timezone(): void
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

        $this->assertSame('Asia/Dhaka', $group->when?->timezoneName);
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

    public function test_throws_when_a_caption_language_has_no_postsyncer_workspace(): void
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
            ],
            'post_types' => [
                'platforms' => ['facebook' => ['text' => 'on']],
                'overrides' => [],
            ],
        ]);
        $config = PostsyncerConfig::fromWorkspace($workspace->fresh());

        $post = Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'English caption'],
        ]);

        $this->expectException(PostsyncerException::class);
        $this->expectExceptionMessage('english');

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
        $this->assertNull($threadsGroup->threadTweets);
    }

    public function test_threads_with_tweet_segments_gets_own_thread_group(): void
    {
        $workspace = Workspace::factory()->create();
        // Images make these photo posts; samplePostTypes keeps Twitter photo off.
        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
            'post_types' => [
                'platforms' => [
                    'twitter' => ['text' => 'on', 'photo' => 'on'],
                    'threads' => ['text' => 'on', 'photo' => 'on'],
                ],
            ],
        ]);
        $workspace->refresh();
        $config = PostsyncerConfig::fromWorkspace($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['twitter', 'threads'],
            'captions' => [
                'English' => [
                    'twitter' => [
                        'caption' => 'Tweet one',
                        'thread' => ['Tweet two'],
                        'images' => [
                            'https://drive.google.com/file/d/tw1/view',
                            'https://drive.google.com/file/d/tw2/view',
                        ],
                    ],
                    'threads' => [
                        'caption' => 'Threads one',
                        'thread' => ['Threads two', 'Threads three'],
                        'images' => [
                            'https://drive.google.com/file/d/th1/view',
                            'https://drive.google.com/file/d/th2/view',
                            'https://drive.google.com/file/d/th3/view',
                        ],
                    ],
                ],
            ],
        ]);

        $groups = $this->planner->plan($post, $config, ['confirm_ask' => true]);

        $twitterGroup = collect($groups)->first(
            fn (PublishGroup $g) => $g->platforms === ['twitter'],
        );
        $threadsGroup = collect($groups)->first(
            fn (PublishGroup $g) => $g->platforms === ['threads'],
        );

        $this->assertNotNull($twitterGroup);
        $this->assertSame(['Tweet one', 'Tweet two'], $twitterGroup->threadTweets);
        $this->assertSame([
            'https://drive.usercontent.google.com/download?id=tw1&export=download&confirm=t',
            'https://drive.usercontent.google.com/download?id=tw2&export=download&confirm=t',
        ], $twitterGroup->mediaUrls);

        $this->assertNotNull($threadsGroup);
        $this->assertSame(['Threads one', 'Threads two', 'Threads three'], $threadsGroup->threadTweets);
        $this->assertSame([
            'https://drive.usercontent.google.com/download?id=th1&export=download&confirm=t',
            'https://drive.usercontent.google.com/download?id=th2&export=download&confirm=t',
            'https://drive.usercontent.google.com/download?id=th3&export=download&confirm=t',
        ], $threadsGroup->mediaUrls);
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
        $this->assertContains(['https://drive.usercontent.google.com/download?id=a&export=download&confirm=t'], $mediaSets);
        $this->assertContains(['https://drive.usercontent.google.com/download?id=b&export=download&confirm=t'], $mediaSets);
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

        $this->assertSame(['https://drive.usercontent.google.com/download?id=p48-bn-cover&export=download&confirm=t'], $bangla->mediaUrls);
        $this->assertSame(['https://drive.usercontent.google.com/download?id=p48-en-cover&export=download&confirm=t'], $english->mediaUrls);
    }

    public function test_first_comment_is_carried_on_facebook_instagram_groups(): void
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
                        'first_comment' => 'Source numbers',
                        'images' => ['https://drive.google.com/file/d/a/view'],
                    ],
                    'instagram' => [
                        'caption' => 'IG',
                        'first_comment' => 'Source numbers',
                        'images' => ['https://drive.google.com/file/d/a/view'],
                    ],
                ],
            ],
        ]);

        $groups = $this->planner->plan($post, $config, ['confirm_ask' => false]);

        $this->assertCount(1, $groups);
        $this->assertSame(['facebook', 'instagram'], $groups[0]->platforms);
        $this->assertSame('Source numbers', $groups[0]->firstComment);
    }

    public function test_facebook_with_first_comment_splits_from_threads(): void
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['account_id' => 100, 'handle' => '@harun'],
                        'threads' => ['account_id' => 200, 'handle' => '@harun'],
                    ],
                ],
            ],
            'post_types' => [
                'platforms' => [
                    'facebook' => ['text' => 'on', 'photo' => 'on'],
                    'threads' => ['text' => 'on', 'photo' => 'on'],
                ],
                'overrides' => [],
            ],
        ]);
        $config = PostsyncerConfig::fromWorkspace($workspace->fresh());

        $post = Post::factory()->for($workspace)->create([
            'language' => 'bn',
            'platforms' => ['facebook', 'threads'],
            'captions' => [
                'main' => [
                    'facebook' => [
                        'caption' => 'FB',
                        'first_comment' => 'Source numbers',
                        'images' => ['https://drive.google.com/file/d/a/view'],
                    ],
                    'threads' => [
                        'caption' => 'Threads',
                        'first_comment' => 'Source numbers',
                        'images' => ['https://drive.google.com/file/d/a/view'],
                    ],
                ],
            ],
        ]);

        $groups = $this->planner->plan($post, $config, ['confirm_ask' => false]);

        $facebook = collect($groups)->first(fn (PublishGroup $g) => $g->platforms === ['facebook']);
        $threads = collect($groups)->first(fn (PublishGroup $g) => $g->platforms === ['threads']);

        $this->assertNotNull($facebook);
        $this->assertSame('Source numbers', $facebook->firstComment);
        $this->assertNotNull($threads);
        $this->assertNull($threads->firstComment);
    }

    public function test_unresolvable_named_images_throw_instead_of_planning_text(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => [
                'main' => [
                    'facebook' => [
                        'caption' => 'FB',
                        'images' => ['missing-cover.png'],
                    ],
                ],
            ],
        ]);

        $this->expectException(PostsyncerException::class);
        $this->expectExceptionMessage('missing-cover.png');

        $this->planner->plan($post, $config, ['confirm_ask' => false]);
    }

    public function test_empty_images_list_keeps_twitter_as_text_not_photo(): void
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'languages' => [
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
            'post_types' => [
                'platforms' => [
                    'twitter' => ['text' => 'on', 'photo' => 'off'],
                    'facebook' => ['text' => 'on', 'photo' => 'on'],
                ],
                'overrides' => [
                    'english' => [
                        'twitter' => ['photo' => 'off'],
                    ],
                ],
            ],
        ]);
        $config = PostsyncerConfig::fromWorkspace($workspace->fresh());

        $post = Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['twitter', 'facebook'],
            'image_drive_urls' => ['https://drive.google.com/file/d/cover/view'],
            'captions' => [
                'English' => [
                    'twitter' => [
                        'caption' => 'Text only tweet',
                        'images' => [],
                    ],
                    'facebook' => [
                        'caption' => 'FB with cover',
                        'images' => ['https://drive.google.com/file/d/cover/view'],
                    ],
                ],
            ],
        ]);

        $groups = $this->planner->plan($post, $config, ['confirm_ask' => false]);

        $twitter = collect($groups)->first(fn (PublishGroup $g) => in_array('twitter', $g->platforms, true));
        $this->assertNotNull($twitter);
        $this->assertSame([], $twitter->mediaUrls);
    }

    public function test_confirm_ask_allows_matrix_off_platform(): void
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'languages' => [
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
            ],
            'post_types' => [
                'platforms' => [
                    'twitter' => ['text' => 'on', 'photo' => 'on', 'thread' => 'on'],
                ],
                'overrides' => [
                    'bangla' => [
                        'twitter' => ['text' => 'off', 'photo' => 'off', 'thread' => 'off'],
                    ],
                ],
            ],
        ]);
        $config = PostsyncerConfig::fromWorkspace($workspace->fresh());

        $post = Post::factory()->for($workspace)->create([
            'language' => 'bn',
            'platforms' => ['twitter'],
            'captions' => [
                'Bangla' => [
                    'twitter' => [
                        'caption' => 'Bangla tweet',
                        'images' => [],
                    ],
                ],
            ],
        ]);

        $without = $this->planner->plan($post, $config, ['confirm_ask' => false]);
        $this->assertSame([], $without);

        $with = $this->planner->plan($post, $config, ['confirm_ask' => true]);
        $twitter = collect($with)->first(fn (PublishGroup $g) => $g->platforms === ['twitter']);
        $this->assertNotNull($twitter);
        $this->assertSame(['twitter' => 'Bangla tweet'], $twitter->captions);
    }

    public function test_linkedin_is_split_from_instagram_tiktok_image_set(): void
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'languages' => [
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
            'post_types' => [
                'platforms' => [
                    'instagram' => ['photo' => 'on', 'carousel' => 'on'],
                    'tiktok' => ['photo' => 'on', 'carousel' => 'on'],
                    'linkedin' => ['text' => 'on', 'photo' => 'on', 'carousel' => 'on'],
                ],
            ],
        ]);
        $config = PostsyncerConfig::fromWorkspace($workspace->fresh());

        $slide = 'https://drive.google.com/file/d/slide1/view';
        $post = Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['instagram', 'tiktok', 'linkedin'],
            'captions' => [
                'English' => [
                    'instagram' => ['caption' => 'IG', 'images' => [$slide]],
                    'tiktok' => ['caption' => 'TT', 'images' => [$slide]],
                    'linkedin' => ['caption' => 'LI', 'images' => [$slide]],
                ],
            ],
        ]);

        $groups = $this->planner->plan($post, $config, ['confirm_ask' => true]);

        $linkedin = collect($groups)->first(fn (PublishGroup $g) => $g->platforms === ['linkedin']);
        $carousel = collect($groups)->first(
            fn (PublishGroup $g) => in_array('instagram', $g->platforms, true),
        );

        $this->assertNotNull($linkedin);
        $this->assertNotNull($carousel);
        $this->assertNotContains('linkedin', $carousel->platforms);
        $this->assertSame(['linkedin' => 'LI'], $linkedin->captions);
    }

    public function test_omitted_images_key_still_inherits_default_media(): void
    {
        $workspace = Workspace::factory()->create();
        $config = $this->configFor($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'bn',
            'platforms' => ['facebook'],
            'image_drive_urls' => ['https://drive.google.com/file/d/cover/view'],
            'captions' => [
                'main' => [
                    'facebook' => [
                        'caption' => 'FB inherits cover',
                        // no images key
                    ],
                ],
            ],
        ]);

        $groups = $this->planner->plan($post, $config, ['confirm_ask' => false]);

        $this->assertCount(1, $groups);
        $this->assertSame(['facebook'], $groups[0]->platforms);
        $this->assertNotSame([], $groups[0]->mediaUrls);
    }
}
