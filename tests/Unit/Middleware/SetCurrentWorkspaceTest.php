<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\SetCurrentWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetCurrentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_binds_the_teams_workspace_for_an_authenticated_user()
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);

        $middleware = new SetCurrentWorkspace(app(CurrentWorkspace::class));
        $middleware->handle($this->requestAs($user), fn () => response('ok'));

        $this->assertTrue(Workspace::current()?->is($workspace));
    }

    public function test_it_leaves_no_workspace_bound_for_a_user_with_no_team()
    {
        $user = User::factory()->create(['current_team_id' => null]);

        $middleware = new SetCurrentWorkspace(app(CurrentWorkspace::class));
        $middleware->handle($this->requestAs($user), fn () => response('ok'));

        $this->assertNull(Workspace::current());
    }

    public function test_it_leaves_no_workspace_bound_for_a_guest()
    {
        $middleware = new SetCurrentWorkspace(app(CurrentWorkspace::class));
        $middleware->handle($this->requestAs(null), fn () => response('ok'));

        $this->assertNull(Workspace::current());
    }
}
