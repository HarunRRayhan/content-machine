<?php

namespace Tests\Feature\Posts;

use App\Actions\Postsyncer\PublishPostAction;
use App\Models\Attachment;
use App\Models\Idea;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PostsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsWorkspaceMember(): array
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        return [$user, $workspace];
    }

    public function test_guests_cannot_view_posts()
    {
        $this->get(route('posts.index'))->assertRedirect(route('login'));
    }

    public function test_legacy_dashboard_index_redirects_to_posts(): void
    {
        $this->actingAsWorkspaceMember();

        $this->get('/dashboard/posts')->assertRedirect('/posts');
    }

    public function test_index_only_lists_the_current_workspaces_posts()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $mine = Post::factory()->for($workspace)->create(['title' => 'Mine']);

        $otherWorkspace = Workspace::factory()->create();
        Post::factory()->for($otherWorkspace)->create(['title' => 'Not mine']);

        $this->get(route('posts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/index')
                ->has('items.data', 1)
                ->where('items.data.0.type', 'post')
                ->where('items.data.0.id', $mine->id)
                ->where('filters.status', 'draft')
            );
    }

    public function test_index_defaults_to_draft_tab_when_status_is_missing(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Post::factory()->for($workspace)->create(['title' => 'Draft one', 'status' => 'draft']);
        Post::factory()->for($workspace)->create(['title' => 'Scheduled one', 'status' => 'scheduled']);

        $this->get(route('posts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/index')
                ->has('items.data', 1)
                ->where('items.data.0.title', 'Draft one')
                ->where('filters.status', 'draft')
            );
    }

    public function test_index_orders_posts_by_number_desc_not_created_at(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Post::factory()->for($workspace)->create([
            'human_id' => 'P-45',
            'number' => 45,
            'title' => 'Older import, higher number',
            'status' => 'draft',
            'created_at' => now()->subDays(2),
        ]);
        Post::factory()->for($workspace)->create([
            'human_id' => 'P-47',
            'number' => 47,
            'title' => 'Newest import',
            'status' => 'draft',
            'created_at' => now(),
        ]);
        Post::factory()->for($workspace)->create([
            'human_id' => 'P-42',
            'number' => 42,
            'title' => 'Oldest import, lower number',
            'status' => 'draft',
            'created_at' => now()->subDays(5),
        ]);

        $this->get(route('posts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/index')
                ->has('items.data', 3)
                ->where('items.data.0.human_id', 'P-47')
                ->where('items.data.1.human_id', 'P-45')
                ->where('items.data.2.human_id', 'P-42')
            );
    }

    public function test_index_ideation_tab_lists_open_post_ideas(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $idea = Idea::factory()->for($workspace)->create([
            'kind' => 'post',
            'status' => 'open',
            'title' => 'Post idea',
            'score' => 720,
            'trend' => 'evergreen',
        ]);

        Idea::factory()->for($workspace)->promoted()->create(['kind' => 'post', 'title' => 'Promoted']);
        Idea::factory()->for($workspace)->create(['kind' => 'video', 'status' => 'open', 'title' => 'Video idea']);

        $otherWorkspace = Workspace::factory()->create();
        Idea::factory()->for($otherWorkspace)->create(['kind' => 'post', 'status' => 'open']);

        $this->get(route('posts.index', ['status' => 'ideation']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/index')
                ->has('items.data', 1)
                ->where('items.data.0.type', 'idea')
                ->where('items.data.0.id', $idea->id)
                ->where('items.data.0.title', 'Post idea')
                ->where('items.data.0.score', 720)
                ->where('items.data.0.trend', 'evergreen')
                ->where('filters.status', 'ideation')
            );
    }

    public function test_index_exposes_counts_for_all_status_tabs(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Post::factory()->for($workspace)->count(2)->create(['status' => 'draft']);
        Post::factory()->for($workspace)->create(['status' => 'ready']);
        Post::factory()->for($workspace)->create(['status' => 'scheduled']);
        Idea::factory()->for($workspace)->count(3)->create(['kind' => 'post', 'status' => 'open']);

        $this->get(route('posts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.ideation', 3)
                ->where('counts.draft', 2)
                ->where('counts.ready', 1)
                ->where('counts.scheduled', 1)
                ->where('counts.posted', 0)
                ->where('counts.archived', 0)
                ->where('counts.dropped', 0)
            );
    }

    public function test_index_filters_posts_by_status_tab(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Post::factory()->for($workspace)->create(['title' => 'Ready one', 'status' => 'ready']);
        Post::factory()->for($workspace)->create(['title' => 'Draft one', 'status' => 'draft']);

        $this->get(route('posts.index', ['status' => 'ready']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/index')
                ->has('items.data', 1)
                ->where('items.data.0.type', 'post')
                ->where('items.data.0.title', 'Ready one')
                ->where('filters.status', 'ready')
            );
    }

    public function test_index_exposes_postsyncer_groups_for_workspace_chips(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $groups = [
            [
                'post_id' => 132739,
                'status' => 'SCHEDULED',
                'platforms' => ['facebook'],
                'lang' => 'bangla',
            ],
            [
                'post_id' => 132743,
                'status' => 'SCHEDULED',
                'platforms' => ['twitter'],
                'lang' => 'english',
            ],
        ];

        Post::factory()->for($workspace)->create([
            'title' => 'Scheduled bilingual',
            'status' => 'scheduled',
            'language' => 'bn',
            'platforms' => ['facebook', 'twitter'],
            'postsyncer' => ['groups' => $groups],
        ]);

        $this->get(route('posts.index', ['status' => 'scheduled']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/index')
                ->has('items.data', 1)
                ->where('items.data.0.groups', $groups)
                ->where('items.data.0.workspaces', [
                    ['key' => 'en', 'groups' => [], 'platforms' => ['twitter']],
                    ['key' => 'bn', 'groups' => [], 'platforms' => ['facebook']],
                ])
            );
    }

    public function test_index_exposes_both_workspaces_from_captions_when_unscheduled(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $captions = json_decode(
            file_get_contents(base_path('tests/Fixtures/postsyncer/p48_captions.json')),
            true,
        );

        Post::factory()->for($workspace)->create([
            'title' => 'Draft bilingual',
            'status' => 'draft',
            'language' => 'both',
            'platforms' => ['facebook', 'twitter'],
            'captions' => $captions,
            'postsyncer' => null,
        ]);

        $this->get(route('posts.index', ['status' => 'draft']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/index')
                ->has('items.data', 1)
                ->where('items.data.0.workspaces.0.key', 'en')
                ->where('items.data.0.workspaces.1.key', 'bn')
            );
    }

    public function test_show_renders_a_post_in_the_current_workspace()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $workspace->update(['timezone' => 'America/New_York']);

        $post = Post::factory()->for($workspace)->create(['title' => 'Hello post']);

        $this->get(route('posts.show', $post))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/show')
                ->where('post.id', $post->id)
                ->where('post.title', 'Hello post')
                ->where('post.timezone', 'America/New_York')
                ->where('post.publish_state', $post->publish_state)
                ->where('post.postsyncer_ready', false)
                ->has('post.needs_confirm_ask')
                ->has('post.postsyncer')
            );
    }

    public function test_show_exposes_handles_from_both_postsyncer_workspaces(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        Cache::flush();

        PostsyncerConfig::write($workspace, [
            'api_key' => 'test-api-key',
            'api_base' => 'https://postsyncer.com/api/v1',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => [
                        'facebook' => ['handle' => 'HarunRRayhan', 'account_id' => 7017],
                    ],
                ],
                'english' => [
                    'workspace_id' => '853',
                    'platforms' => [
                        'twitter' => ['handle' => 'old-english-twitter', 'account_id' => 1205],
                    ],
                ],
            ],
        ]);
        $workspace->refresh();

        Http::fake([
            'postsyncer.com/api/v1/accounts' => Http::response([
                [
                    'id' => 7017,
                    'workspace_id' => 15211,
                    'platform' => 'facebook',
                    'username' => null,
                    'name' => 'Harun R.',
                ],
                [
                    'id' => 7368,
                    'workspace_id' => 15211,
                    'platform' => 'twitter',
                    'username' => 'HarunRRayhan',
                    'name' => 'Harun R. Rayhan',
                ],
                [
                    'id' => 1205,
                    'workspace_id' => 853,
                    'platform' => 'twitter',
                    'username' => 'harundotdev',
                    'name' => 'Harun R.',
                ],
                [
                    'id' => 4936,
                    'workspace_id' => 42761,
                    'platform' => 'instagram',
                    'username' => 'armansedits',
                    'name' => 'Arman',
                ],
            ], 200),
        ]);

        $post = Post::factory()->for($workspace)->create(['title' => 'Handle post']);

        $this->get(route('posts.show', $post))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/show')
                ->where('post.handles.bn.facebook.handle', 'HarunRRayhan')
                ->where('post.handles.bn.twitter.handle', 'HarunRRayhan')
                ->where('post.handles.en.twitter.handle', 'harundotdev')
                ->where('post.handles.en.instagram.handle', 'harundotdev')
            );
    }

    public function test_show_resolves_a_prefixed_human_id_on_the_short_url(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $post = Post::factory()->for($workspace)->create([
            'title' => 'Custom id post',
            'human_id' => 'P-50',
            'number' => 50,
        ]);

        $this->get('/posts/P-50')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/show')
                ->where('post.id', $post->id)
                ->where('post.human_id', 'P-50')
                ->where('post.title', 'Custom id post')
            );
    }

    public function test_show_resolves_an_imported_bangla_human_id(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $post = Post::factory()->for($workspace)->create([
            'title' => 'Imported post',
            'human_id' => 'BP-12',
            'number' => 12,
        ]);

        $this->get('/posts/BP-12')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/show')
                ->where('post.id', $post->id)
                ->where('post.human_id', 'BP-12')
            );
    }

    public function test_legacy_dashboard_show_redirects_to_posts_show(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Post::factory()->for($workspace)->create([
            'title' => 'Dashboard custom id',
            'human_id' => 'P-50',
            'number' => 50,
        ]);

        $this->get('/dashboard/posts/P-50')->assertRedirect('/posts/P-50');
    }

    public function test_short_url_still_resolves_a_numeric_database_id(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $post = Post::factory()->for($workspace)->create(['title' => 'Numeric id post']);

        $this->get('/posts/'.$post->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/show')
                ->where('post.id', $post->id)
            );
    }

    public function test_short_url_404s_for_a_prefixed_id_in_another_workspace(): void
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        Post::factory()->for($otherWorkspace)->create(['human_id' => 'P-50', 'number' => 50]);

        $this->get('/posts/P-50')->assertNotFound();
    }

    public function test_show_exposes_session_media_urls_for_attached_images(): void
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $post = Post::factory()->for($workspace)->create([
            'title' => 'With cover',
            'captions' => [
                'main' => [
                    'facebook' => [
                        'caption' => 'Hello',
                        // omitted images → inherit attached cover for preview
                    ],
                ],
            ],
        ]);

        $media = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => $workspace->id.'/cover.png',
            'original_filename' => 'cover.png',
            'mime' => 'image/png',
        ]);
        Storage::disk('scratchpad')->put($media->path, 'png-bytes');
        Attachment::factory()->for($post, 'attachable')->for($media)->create([
            'role' => 'image',
        ]);

        $expectedUrl = route('posts.media', [$post, $media]);

        $this->get(route('posts.show', $post))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/show')
                ->where('post.images.0.url', $expectedUrl)
                ->where('post.images.0.filename', 'cover.png')
                ->where('post.captions.0.platforms.0.images.0', 'cover.png')
                ->where('post.image_urls', fn ($urls) => ($urls['cover.png'] ?? null) === $expectedUrl)
            );
    }

    public function test_show_exposes_a_linkedin_carousel_pdf_in_image_urls(): void
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $post = Post::factory()->for($workspace)->create([
            'human_id' => 'P-50',
            'number' => 50,
            'title' => 'N+1',
            'captions' => [
                'English' => [
                    'linkedin' => [
                        'caption' => 'Carousel teaser',
                        'images' => ['en-1.png', 'en-2.png'],
                    ],
                ],
            ],
        ]);

        $media = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => $workspace->id.'/p-50-linkedin-carousel.pdf',
            'original_filename' => 'p-50-linkedin-carousel.pdf',
            'mime' => 'application/pdf',
            'kind' => 'document',
        ]);
        Storage::disk('scratchpad')->put($media->path, '%PDF-1.4');
        Attachment::factory()->for($post, 'attachable')->for($media)->create([
            'role' => 'document',
            'platform' => 'linkedin',
        ]);

        $expectedUrl = route('posts.media', [$post, $media]);

        $this->get(route('posts.show', $post))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/show')
                ->where('post.image_urls', fn ($urls) => ($urls['p-50-linkedin-carousel.pdf'] ?? null) === $expectedUrl)
            );
    }

    public function test_show_keeps_per_platform_image_lists_and_does_not_fill_an_explicit_none(): void
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $post = Post::factory()->for($workspace)->create([
            'title' => 'Split images',
            'captions' => [
                'English' => [
                    'twitter' => [
                        'caption' => 'No photo',
                        'images' => [],
                    ],
                    'facebook' => [
                        'caption' => 'Cover only',
                        'images' => ['en-cover.png'],
                    ],
                ],
                'Bangla' => [
                    'facebook' => [
                        'caption' => 'Bangla cover',
                        'images' => ['bn-cover.png'],
                    ],
                ],
            ],
        ]);

        foreach (['en-cover.png', 'bn-cover.png', 'extra-slide.png'] as $filename) {
            $media = MediaAsset::factory()->for($workspace)->create([
                'disk' => 'scratchpad',
                'path' => $workspace->id.'/'.$filename,
                'original_filename' => $filename,
                'mime' => 'image/png',
            ]);
            Storage::disk('scratchpad')->put($media->path, $filename);
            Attachment::factory()->for($post, 'attachable')->for($media)->create([
                'role' => 'image',
            ]);
        }

        $this->get(route('posts.show', $post))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/show')
                ->where('post.captions', function (mixed $groups): bool {
                    $byLang = [];
                    foreach ($groups as $group) {
                        $byLang[$group['lang']] = [];
                        foreach ($group['platforms'] as $platform) {
                            $byLang[$group['lang']][$platform['name']] = $platform['images'];
                        }
                    }

                    return ($byLang['en']['twitter'] ?? null) === []
                        && ($byLang['en']['facebook'] ?? null) === ['en-cover.png']
                        && ($byLang['bn']['facebook'] ?? null) === ['bn-cover.png'];
                })
            );
    }

    public function test_media_streams_an_attached_image_for_the_current_workspace(): void
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $post = Post::factory()->for($workspace)->create();
        $media = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => $workspace->id.'/view.jpg',
            'original_filename' => 'view.jpg',
            'mime' => 'image/jpeg',
        ]);
        Storage::disk('scratchpad')->put($media->path, 'jpeg-bytes');
        Attachment::factory()->for($post, 'attachable')->for($media)->create();

        $this->get(route('posts.media', [$post, $media]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_media_404s_for_an_asset_in_a_different_workspace(): void
    {
        Storage::fake('scratchpad');
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $otherPost = Post::factory()->for($otherWorkspace)->create();
        $media = MediaAsset::factory()->for($otherWorkspace)->create([
            'disk' => 'scratchpad',
            'path' => $otherWorkspace->id.'/secret.jpg',
            'mime' => 'image/jpeg',
        ]);
        Storage::disk('scratchpad')->put($media->path, 'secret');
        Attachment::factory()->for($otherPost, 'attachable')->for($media)->create();

        $this->get(route('posts.media', [$otherPost, $media]))->assertNotFound();
    }

    public function test_show_404s_for_a_post_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $post = Post::factory()->for($otherWorkspace)->create();

        $this->get(route('posts.show', $post))->assertNotFound();
    }

    public function test_update_edits_the_post()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $post = Post::factory()->for($workspace)->create(['title' => 'Old']);

        $response = $this->patch(route('posts.update', $post), [
            'title' => 'New',
            'body' => 'Updated body.',
        ]);

        $response->assertRedirect(route('posts.show', $post));

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'title' => 'New',
            'body' => 'Updated body.',
        ]);
    }

    public function test_update_accepts_caption_edits_and_reopens_approval(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $post = Post::factory()->for($workspace)->create([
            'approval_state' => 'approved',
            'body' => 'Old body.',
            'captions' => ['facebook' => ['caption' => 'Old caption']],
        ]);

        $response = $this->patch(route('posts.update', $post), [
            'title' => $post->title,
            'body' => 'New body.',
            'captions' => ['facebook' => ['caption' => 'New caption']],
        ]);

        $response->assertRedirect(route('posts.show', $post));
        $post->refresh();
        $this->assertSame('New body.', $post->body);
        $this->assertSame(['facebook' => ['caption' => 'New caption']], $post->captions);
        $this->assertSame('pending', $post->approval_state);
    }

    public function test_update_404s_for_a_post_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $post = Post::factory()->for($otherWorkspace)->create();

        $this->patch(route('posts.update', $post), ['title' => 'Nope'])->assertNotFound();
    }

    public function test_show_exposes_postsyncer_groups_for_a_scheduled_post(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $post = Post::factory()->for($workspace)->create([
            'title' => 'Already scheduled',
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [[
                    'post_id' => '132531',
                    'status' => 'SCHEDULED',
                    'scheduled_at' => '2026-08-26T21:18:00+06:00',
                    'platforms' => ['facebook'],
                    'language' => 'bangla',
                ]],
            ],
        ]);

        $this->get(route('posts.show', $post))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/show')
                ->where('post.status', 'scheduled')
                ->where('post.postsyncer.groups.0.post_id', '132531')
            );
    }

    public function test_reconcile_route_verifies_and_checkpoints_an_uncertain_post(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        PostsyncerConfig::write($workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => [
                    'workspace_id' => '15211',
                    'platforms' => ['facebook' => ['account_id' => 100]],
                ],
            ],
            'post_types' => [
                'platforms' => ['facebook' => ['text' => 'on']],
                'overrides' => [],
            ],
        ]);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'ready',
            'language' => 'bn',
            'platforms' => ['facebook'],
            'captions' => ['facebook' => 'Reconcile this post'],
        ]);

        Http::fake([
            'postsyncer.com/api/v1/posts' => Http::response(['message' => 'gateway timeout'], 500),
        ]);

        try {
            app(PublishPostAction::class)->handle($post, [
                'when' => '2099-09-03T09:00:00+06:00',
            ]);
        } catch (\Throwable) {
            // The route repairs the uncertain state left by this attempt.
        }

        Http::fake([
            'postsyncer.com/api/v1/posts/99' => Http::response([
                'id' => 99,
                'workspace_id' => 15211,
                'content' => [['text' => 'Reconcile this post', 'media' => []]],
                'platforms' => [['platform' => 'facebook']],
                'status' => 'SCHEDULED',
                'scheduled_at' => '2099-09-03T09:00:00+06:00',
            ], 200),
        ]);

        $this->post(route('posts.reconcile', $post), ['postsyncer_id' => '99'])
            ->assertRedirect(route('posts.show', $post));

        $this->assertSame('99', $post->refresh()->publish_progress['completed_groups'][0]['post_id']);
    }
}
