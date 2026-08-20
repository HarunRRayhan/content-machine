<?php

namespace Tests\Feature\Scratchpad;

use App\Models\Idea;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScratchpadControllerTest extends TestCase
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

    public function test_guests_cannot_view_the_scratchpad()
    {
        $this->get(route('dashboard.scratchpad.index'))->assertRedirect(route('login'));
    }

    public function test_index_only_lists_the_current_workspaces_entries()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $mine = ScratchpadEntry::factory()->for($workspace)->create(['body' => 'Mine']);

        $otherWorkspace = Workspace::factory()->create();
        ScratchpadEntry::factory()->for($otherWorkspace)->create(['body' => 'Not mine']);

        $this->get(route('dashboard.scratchpad.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('scratchpad/index')
                ->has('entries.data', 1)
                ->where('entries.data.0.id', $mine->id)
            );
    }

    public function test_store_creates_an_entry_and_redirects()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $response = $this->post(route('dashboard.scratchpad.store'), [
            'body' => 'A quick captured thought.',
        ]);

        $response->assertRedirect(route('dashboard.scratchpad.index'));

        $this->assertDatabaseHas('scratchpad_entries', [
            'workspace_id' => $workspace->id,
            'kind' => 'text',
            'source' => 'web',
            'status' => 'new',
            'body' => 'A quick captured thought.',
        ]);
    }

    public function test_store_records_a_status_transition_on_capture()
    {
        [$user] = $this->actingAsWorkspaceMember();

        $this->post(route('dashboard.scratchpad.store'), [
            'body' => 'A quick captured thought.',
        ]);

        $entry = ScratchpadEntry::sole();

        $this->assertDatabaseHas('status_transitions', [
            'subject_type' => $entry->getMorphClass(),
            'subject_id' => $entry->id,
            'from' => null,
            'to' => 'new',
            'actor_type' => 'user',
            'actor_id' => $user->id,
        ]);
    }

    public function test_store_validates_an_empty_body()
    {
        $this->actingAsWorkspaceMember();

        $response = $this->post(route('dashboard.scratchpad.store'), [
            'body' => '',
        ]);

        $response->assertSessionHasErrors(['body']);
        $this->assertDatabaseCount('scratchpad_entries', 0);
    }

    public function test_show_renders_an_entry_in_the_current_workspace()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $entry = ScratchpadEntry::factory()->for($workspace)->create(['body' => 'Hello there']);

        $this->get(route('dashboard.scratchpad.show', $entry))
            ->assertInertia(fn (Assert $page) => $page
                ->component('scratchpad/show')
                ->where('entry.id', $entry->id)
                ->where('entry.body', 'Hello there')
            );
    }

    public function test_show_404s_for_an_entry_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->for($otherWorkspace)->create();

        $this->get(route('dashboard.scratchpad.show', $entry))->assertNotFound();
    }

    public function test_triage_as_post_idea_files_an_idea_and_marks_the_entry_triaged()
    {
        [$user, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->create(['body' => 'Raw capture.']);

        $response = $this->post(route('dashboard.scratchpad.triage', $entry), [
            'target' => 'post_idea',
            'title' => 'A filed idea',
            'score' => 600,
            'trend' => 'seasonal',
            'rationale' => 'Timely.',
        ]);

        $response->assertRedirect(route('dashboard.scratchpad.show', $entry));

        $this->assertDatabaseHas('scratchpad_entries', [
            'id' => $entry->id,
            'status' => 'triaged',
            'triaged_by_user_id' => $user->id,
        ]);

        $idea = Idea::sole();
        $this->assertSame('post', $idea->kind);
        $this->assertSame('A filed idea', $idea->title);
        $this->assertSame($entry->id, $idea->scratchpad_entry_id);
    }

    public function test_triage_as_video_idea_files_a_video_idea()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        $this->post(route('dashboard.scratchpad.triage', $entry), [
            'target' => 'video_idea',
            'title' => 'A filed video idea',
        ])->assertRedirect(route('dashboard.scratchpad.show', $entry));

        $idea = Idea::sole();
        $this->assertSame('video', $idea->kind);
    }

    public function test_triage_requires_a_title_when_filing_an_idea()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        $response = $this->post(route('dashboard.scratchpad.triage', $entry), [
            'target' => 'post_idea',
        ]);

        $response->assertSessionHasErrors(['title']);
        $this->assertDatabaseCount('ideas', 0);
    }

    public function test_triage_as_drop_marks_the_entry_dropped_with_a_reason()
    {
        [$user, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        $response = $this->post(route('dashboard.scratchpad.triage', $entry), [
            'target' => 'drop',
            'drop_reason' => 'Not useful.',
        ]);

        $response->assertRedirect(route('dashboard.scratchpad.show', $entry));

        $this->assertDatabaseHas('scratchpad_entries', [
            'id' => $entry->id,
            'status' => 'dropped',
            'drop_reason' => 'Not useful.',
            'triaged_by_user_id' => $user->id,
        ]);
        $this->assertDatabaseCount('ideas', 0);
    }

    public function test_triage_requires_a_reason_when_dropping()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        $response = $this->post(route('dashboard.scratchpad.triage', $entry), [
            'target' => 'drop',
        ]);

        $response->assertSessionHasErrors(['drop_reason']);
    }

    public function test_triage_404s_for_an_entry_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->for($otherWorkspace)->create();

        $this->post(route('dashboard.scratchpad.triage', $entry), [
            'target' => 'drop',
            'drop_reason' => 'Nope.',
        ])->assertNotFound();
    }

    public function test_an_already_triaged_entry_cannot_be_triaged_again()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->triaged()->create();

        $response = $this->post(route('dashboard.scratchpad.triage', $entry), [
            'target' => 'drop',
            'drop_reason' => 'Too late.',
        ]);

        $response->assertRedirect(route('dashboard.scratchpad.show', $entry));
        $response->assertInertiaFlash('toast.type', 'error');
    }
}
