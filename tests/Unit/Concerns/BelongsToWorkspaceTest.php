<?php

namespace Tests\Unit\Concerns;

use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\WorkspaceScopedFixture;
use Tests\TestCase;

class BelongsToWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('workspace_scoped_fixtures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained('workspaces');
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('workspace_scoped_fixtures');

        parent::tearDown();
    }

    public function test_it_only_returns_rows_for_the_current_workspace()
    {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        WorkspaceScopedFixture::create(['name' => 'in A', 'workspace_id' => $workspaceA->id]);
        WorkspaceScopedFixture::create(['name' => 'in B', 'workspace_id' => $workspaceB->id]);

        app(CurrentWorkspace::class)->set($workspaceA);

        $this->assertSame(['in A'], WorkspaceScopedFixture::pluck('name')->all());
    }

    public function test_it_returns_everything_when_no_workspace_is_current()
    {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        WorkspaceScopedFixture::create(['name' => 'in A', 'workspace_id' => $workspaceA->id]);
        WorkspaceScopedFixture::create(['name' => 'in B', 'workspace_id' => $workspaceB->id]);

        $this->assertCount(2, WorkspaceScopedFixture::all());
    }

    public function test_it_fills_the_workspace_id_from_the_current_workspace_on_create()
    {
        $workspace = Workspace::factory()->create();
        app(CurrentWorkspace::class)->set($workspace);

        $fixture = WorkspaceScopedFixture::create(['name' => 'auto-filled']);

        $this->assertSame($workspace->id, $fixture->workspace_id);
    }
}
