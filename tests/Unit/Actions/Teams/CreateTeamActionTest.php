<?php

namespace Tests\Unit\Actions\Teams;

use App\Actions\Teams\CreateTeamAction;
use App\Data\Teams\CreateTeamData;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateTeamActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_team_with_a_default_workspace_and_owner_membership()
    {
        $owner = User::factory()->create(['name' => 'Ada Lovelace']);

        $team = (new CreateTeamAction)->handle($owner, new CreateTeamData(name: "Ada Lovelace's Team"));

        $this->assertSame("Ada Lovelace's Team", $team->name);
        $this->assertSame('ada-lovelaces-team', $team->slug);
        $this->assertSame($owner->id, $team->owner_id);

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        $workspace = $team->workspaces()->sole();
        $this->assertSame('Default', $workspace->name);
        $this->assertSame('default', $workspace->slug);
    }

    public function test_it_uniqueifies_the_slug_on_collision()
    {
        $existingOwner = User::factory()->create(['name' => 'Grace Hopper']);
        (new CreateTeamAction)->handle($existingOwner, CreateTeamData::fromOwner($existingOwner));

        $secondOwner = User::factory()->create(['name' => 'Grace Hopper']);
        $secondTeam = (new CreateTeamAction)->handle($secondOwner, CreateTeamData::fromOwner($secondOwner));

        $this->assertSame('grace-hoppers-team-2', $secondTeam->slug);
        $this->assertSame(2, Team::where('name', "Grace Hopper's Team")->count());
    }

    public function test_it_does_not_change_the_owners_current_team(): void
    {
        $owner = User::factory()->create(['current_team_id' => null]);

        (new CreateTeamAction)->handle($owner, CreateTeamData::fromOwner($owner));

        $this->assertNull($owner->fresh()->current_team_id);
    }
}
