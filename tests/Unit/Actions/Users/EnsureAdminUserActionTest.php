<?php

namespace Tests\Unit\Actions\Users;

use App\Actions\Teams\CreateTeamAction;
use App\Actions\Users\EnsureAdminUserAction;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureAdminUserActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_user_with_a_personal_team_and_a_generated_password()
    {
        $result = (new EnsureAdminUserAction(new CreateTeamAction))->handle('admin@example.com', 'Admin');

        $this->assertNotNull($result);
        $user = $result['user'];

        $this->assertSame('admin@example.com', $user->email);
        $this->assertSame('Admin', $user->name);
        $this->assertNotEmpty($result['password']);
        $this->assertTrue(password_verify($result['password'], $user->fresh()->password));

        $this->assertNotNull($user->current_team_id);
        $this->assertDatabaseHas('team_user', [
            'team_id' => $user->current_team_id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $this->assertSame(1, $user->currentTeam->workspaces()->count());
    }

    public function test_it_is_a_no_op_when_a_user_with_that_email_already_exists()
    {
        User::factory()->create(['email' => 'admin@example.com']);

        $result = (new EnsureAdminUserAction(new CreateTeamAction))->handle('admin@example.com', 'Admin');

        $this->assertNull($result);
        $this->assertSame(1, User::where('email', 'admin@example.com')->count());
        $this->assertSame(0, Team::count());
    }
}
