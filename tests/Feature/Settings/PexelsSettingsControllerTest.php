<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Pexels\PexelsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class PexelsSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private function owner(Workspace $workspace): User
    {
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);
        $this->actingAs($user);

        return $user;
    }

    public function test_workspace_admin_can_save_encrypted_key_without_receiving_it_back(): void
    {
        $workspace = Workspace::factory()->create();
        $this->owner($workspace);

        $this->post('/settings/pexels', ['api_key' => 'private-pexels-key'])
            ->assertRedirect(route('settings.pexels.edit'));

        $stored = $workspace->fresh()->settings['pexels']['api_key'];
        $this->assertNotSame('private-pexels-key', $stored);
        $this->assertSame('private-pexels-key', Crypt::decryptString($stored));
        $this->assertTrue(PexelsConfig::fromWorkspace($workspace->fresh())->isConfigured());
        $this->get(route('settings.pexels.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('workspace-settings/pexels')
                ->where('apiKeyConfigured', true)
                ->missing('apiKey'));
    }

    public function test_non_admin_cannot_change_workspace_pexels_key(): void
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'member']);

        $this->actingAs($user)->post('/settings/pexels', ['api_key' => 'forbidden-key'])->assertForbidden();
        $this->assertArrayNotHasKey('pexels', $workspace->fresh()->settings ?? []);
    }
}
