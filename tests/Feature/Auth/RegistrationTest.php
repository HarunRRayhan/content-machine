<?php

namespace Tests\Feature\Auth;

use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard.home', absolute: false));
    }

    public function test_registering_creates_a_personal_team_and_default_workspace()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::whereEmail('test@example.com')->firstOrFail();

        $this->assertNotNull($user->current_team_id);

        // Guards against the Registered listener firing twice (it did,
        // silently, until event auto-discovery + an explicit Event::listen
        // registration were both wiring it up at once).
        $this->assertSame(1, Team::count());
        $this->assertSame(1, Workspace::count());

        $team = $user->currentTeam;
        $this->assertSame("Test User's Team", $team->name);
        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $workspace = $team->workspaces()->sole();
        $this->assertSame('Default', $workspace->name);
        $this->assertSame('default', $workspace->slug);
    }
}
