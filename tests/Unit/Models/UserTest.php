<?php

namespace Tests\Unit\Models;

use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_team_updates_current_team_for_a_member()
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => null]);
        $team->members()->attach($user->id, ['role' => 'member']);

        $user->switchTeam($team);

        $this->assertSame($team->id, $user->fresh()->current_team_id);
    }

    public function test_switch_team_rejects_a_team_the_user_does_not_belong_to()
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => null]);

        $this->expectException(AuthorizationException::class);

        $user->switchTeam($team);
    }
}
