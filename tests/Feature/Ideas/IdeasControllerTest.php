<?php

namespace Tests\Feature\Ideas;

use App\Models\Idea;
use App\Models\Post;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IdeasControllerTest extends TestCase
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

    public function test_guests_cannot_view_ideas()
    {
        $this->get(route('dashboard.ideas.index'))->assertRedirect(route('login'));
    }

    public function test_index_only_lists_the_current_workspaces_ideas()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $mine = Idea::factory()->for($workspace)->create(['title' => 'Mine']);

        $otherWorkspace = Workspace::factory()->create();
        Idea::factory()->for($otherWorkspace)->create(['title' => 'Not mine']);

        $this->get(route('dashboard.ideas.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ideas/index')
                ->has('ideas.data', 1)
                ->where('ideas.data.0.id', $mine->id)
            );
    }

    public function test_index_filters_by_kind_and_status()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $post = Idea::factory()->for($workspace)->create(['kind' => 'post', 'status' => 'open']);
        Idea::factory()->for($workspace)->create(['kind' => 'video', 'status' => 'open']);
        Idea::factory()->for($workspace)->dropped()->create(['kind' => 'post']);

        $this->get(route('dashboard.ideas.index', ['kind' => 'post', 'status' => 'open']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ideas/index')
                ->has('ideas.data', 1)
                ->where('ideas.data.0.id', $post->id)
            );
    }

    public function test_show_renders_an_idea_in_the_current_workspace()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $idea = Idea::factory()->for($workspace)->create(['title' => 'Hello idea']);

        $this->get(route('dashboard.ideas.show', $idea))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ideas/show')
                ->where('idea.id', $idea->id)
                ->where('idea.title', 'Hello idea')
            );
    }

    public function test_show_404s_for_an_idea_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $idea = Idea::factory()->for($otherWorkspace)->create();

        $this->get(route('dashboard.ideas.show', $idea))->assertNotFound();
    }

    public function test_update_edits_the_idea()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $idea = Idea::factory()->for($workspace)->create(['title' => 'Old', 'score' => 100]);

        $response = $this->patch(route('dashboard.ideas.update', $idea), [
            'title' => 'New',
            'score' => 950,
            'trend' => 'evergreen',
            'rationale' => 'Because.',
            'body' => 'Updated body.',
        ]);

        $response->assertRedirect(route('dashboard.ideas.show', $idea));

        $this->assertDatabaseHas('ideas', [
            'id' => $idea->id,
            'title' => 'New',
            'score' => 950,
            'trend' => 'evergreen',
        ]);
    }

    public function test_update_404s_for_an_idea_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $idea = Idea::factory()->for($otherWorkspace)->create();

        $this->patch(route('dashboard.ideas.update', $idea), ['title' => 'Nope'])->assertNotFound();
    }

    public function test_drop_marks_the_idea_dropped()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $idea = Idea::factory()->for($workspace)->create(['status' => 'open']);

        $response = $this->post(route('dashboard.ideas.drop', $idea), [
            'drop_reason' => 'No longer relevant.',
        ]);

        $response->assertRedirect(route('dashboard.ideas.show', $idea));

        $this->assertDatabaseHas('ideas', [
            'id' => $idea->id,
            'status' => 'dropped',
            'drop_reason' => 'No longer relevant.',
        ]);
    }

    public function test_promote_creates_a_draft_post_and_shows_it_on_the_idea()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $idea = Idea::factory()->for($workspace)->create(['kind' => 'post', 'status' => 'open', 'title' => 'Promote me']);

        $response = $this->post(route('dashboard.ideas.promote', $idea));

        $response->assertRedirect(route('dashboard.ideas.show', $idea));
        $this->assertDatabaseHas('ideas', ['id' => $idea->id, 'status' => 'promoted']);

        $post = Post::sole();
        $this->assertSame('Promote me', $post->title);
        $this->assertSame($idea->id, $post->idea_id);

        $this->get(route('dashboard.ideas.show', $idea))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ideas/show')
                ->where('idea.status', 'promoted')
                ->where('idea.promoted_to.id', $post->id)
                ->where('idea.promoted_to.kind', 'post')
                ->where('idea.promoted_to.human_id', $post->human_id)
            );
    }

    public function test_promote_404s_for_an_idea_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $idea = Idea::factory()->for($otherWorkspace)->create(['kind' => 'post', 'status' => 'open']);

        $this->post(route('dashboard.ideas.promote', $idea))->assertNotFound();
    }

    public function test_promote_flashes_an_error_for_an_already_promoted_idea()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $idea = Idea::factory()->for($workspace)->promoted()->create(['kind' => 'post']);

        $response = $this->post(route('dashboard.ideas.promote', $idea));

        $response->assertRedirect(route('dashboard.ideas.show', $idea));
        $response->assertInertiaFlash('toast.type', 'error');
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_the_full_triage_then_view_the_idea_flow()
    {
        [$user, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->create(['body' => 'Raw capture.']);

        $this->post(route('scratchpad.triage', $entry), [
            'target' => 'post_idea',
            'title' => 'A promoted idea',
            'score' => 800,
            'trend' => 'evergreen',
            'rationale' => 'Strong fit.',
        ])->assertRedirect(route('scratchpad.show', $entry));

        $idea = Idea::sole();
        $this->assertSame('A promoted idea', $idea->title);
        $this->assertSame($entry->id, $idea->scratchpad_entry_id);
        $this->assertSame($user->id, $idea->created_by_user_id);

        $this->get(route('dashboard.ideas.show', $idea))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ideas/show')
                ->where('idea.title', 'A promoted idea')
                ->where('idea.body', 'Raw capture.')
            );

        $this->get(route('scratchpad.show', $entry))
            ->assertInertia(fn (Assert $page) => $page
                ->component('scratchpad/show')
                ->where('entry.status', 'triaged')
                ->where('entry.idea.id', $idea->id)
            );
    }
}
