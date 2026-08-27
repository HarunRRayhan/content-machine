<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneralSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('settings.general.edit'))
            ->assertRedirect(route('login'));
    }

    public function test_owner_can_view_general_settings(): void
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->get(route('settings.general.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('workspace-settings/general'));
    }
}
