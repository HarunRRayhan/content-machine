<?php

namespace Tests\Feature\Posts;

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
                ->has('posts.data', 1)
                ->where('posts.data.0.id', $mine->id)
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
