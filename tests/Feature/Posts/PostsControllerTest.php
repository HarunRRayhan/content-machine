<?php

namespace Tests\Feature\Posts;

use App\Models\Idea;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->get(route('dashboard.posts.index'))->assertRedirect(route('login'));
    }

    public function test_index_only_lists_the_current_workspaces_posts()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $mine = Post::factory()->for($workspace)->create(['title' => 'Mine']);

        $otherWorkspace = Workspace::factory()->create();
        Post::factory()->for($otherWorkspace)->create(['title' => 'Not mine']);

        $this->get(route('dashboard.posts.index'))
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

        $this->get(route('dashboard.posts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/index')
                ->has('items.data', 1)
                ->where('items.data.0.title', 'Draft one')
                ->where('filters.status', 'draft')
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

        $this->get(route('dashboard.posts.index', ['status' => 'ideation']))
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

        $this->get(route('dashboard.posts.index'))
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

        $this->get(route('dashboard.posts.index', ['status' => 'ready']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/index')
                ->has('items.data', 1)
                ->where('items.data.0.type', 'post')
                ->where('items.data.0.title', 'Ready one')
                ->where('filters.status', 'ready')
            );
    }

    public function test_show_renders_a_post_in_the_current_workspace()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $post = Post::factory()->for($workspace)->create(['title' => 'Hello post']);

        $this->get(route('dashboard.posts.show', $post))
            ->assertInertia(fn (Assert $page) => $page
                ->component('posts/show')
                ->where('post.id', $post->id)
                ->where('post.title', 'Hello post')
            );
    }

    public function test_show_404s_for_a_post_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $post = Post::factory()->for($otherWorkspace)->create();

        $this->get(route('dashboard.posts.show', $post))->assertNotFound();
    }

    public function test_update_edits_the_post()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $post = Post::factory()->for($workspace)->create(['title' => 'Old']);

        $response = $this->patch(route('dashboard.posts.update', $post), [
            'title' => 'New',
            'body' => 'Updated body.',
        ]);

        $response->assertRedirect(route('dashboard.posts.show', $post));

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'title' => 'New',
            'body' => 'Updated body.',
        ]);
    }

    public function test_update_404s_for_a_post_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $post = Post::factory()->for($otherWorkspace)->create();

        $this->patch(route('dashboard.posts.update', $post), ['title' => 'Nope'])->assertNotFound();
    }
}
