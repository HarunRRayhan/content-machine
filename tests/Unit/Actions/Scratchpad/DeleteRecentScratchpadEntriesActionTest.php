<?php

namespace Tests\Unit\Actions\Scratchpad;

use App\Actions\Scratchpad\DeleteRecentScratchpadEntriesAction;
use App\Actions\Scratchpad\DeleteScratchpadEntryAction;
use App\Models\ScratchpadEntry;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteRecentScratchpadEntriesActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(): DeleteRecentScratchpadEntriesAction
    {
        return new DeleteRecentScratchpadEntriesAction(new DeleteScratchpadEntryAction);
    }

    public function test_it_deletes_the_workspaces_untriaged_entries_and_returns_the_count()
    {
        $workspace = Workspace::factory()->create();
        ScratchpadEntry::factory()->for($workspace)->count(3)->create();

        $deleted = $this->action()->handle($workspace);

        $this->assertSame(3, $deleted);
        $this->assertSame(0, ScratchpadEntry::count());
    }

    public function test_it_leaves_triaged_and_dropped_entries_alone()
    {
        $workspace = Workspace::factory()->create();
        ScratchpadEntry::factory()->for($workspace)->triaged()->create();
        ScratchpadEntry::factory()->for($workspace)->dropped()->create();

        $deleted = $this->action()->handle($workspace);

        $this->assertSame(0, $deleted);
        $this->assertSame(2, ScratchpadEntry::count());
    }

    public function test_it_only_targets_the_given_workspace()
    {
        $workspace = Workspace::factory()->create();
        $otherWorkspace = Workspace::factory()->create();
        ScratchpadEntry::factory()->for($workspace)->create();
        $otherEntry = ScratchpadEntry::factory()->for($otherWorkspace)->create();

        $deleted = $this->action()->handle($workspace);

        $this->assertSame(1, $deleted);
        $this->assertSame($otherEntry->id, ScratchpadEntry::sole()->id);
    }

    public function test_it_caps_at_the_ten_most_recent()
    {
        $workspace = Workspace::factory()->create();
        ScratchpadEntry::factory()->for($workspace)->count(12)->create();

        $deleted = $this->action()->handle($workspace);

        $this->assertSame(10, $deleted);
        $this->assertSame(2, ScratchpadEntry::count());
    }
}
