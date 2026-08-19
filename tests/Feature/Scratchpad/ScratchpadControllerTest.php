<?php

namespace Tests\Feature\Scratchpad;

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
}
