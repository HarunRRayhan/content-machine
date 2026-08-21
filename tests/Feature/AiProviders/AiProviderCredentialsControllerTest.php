<?php

namespace Tests\Feature\AiProviders;

use App\Models\AiProviderCredential;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiProviderCredentialsControllerTest extends TestCase
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

    public function test_guests_cannot_view_the_index()
    {
        $this->get(route('dashboard.ai-providers.index'))->assertRedirect(route('login'));
    }

    public function test_index_only_lists_the_current_workspaces_credentials_in_priority_order()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $other = Workspace::factory()->create();
        AiProviderCredential::factory()->for($other)->create(['label' => 'Not mine']);

        AiProviderCredential::factory()->for($workspace)->create(['label' => 'Second', 'priority' => 1]);
        AiProviderCredential::factory()->for($workspace)->create(['label' => 'First', 'priority' => 0]);

        $this->get(route('dashboard.ai-providers.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ai-providers/index')
                ->has('credentials', 2)
                ->where('credentials.0.label', 'First')
                ->where('credentials.1.label', 'Second')
            );
    }

    public function test_index_never_exposes_the_api_key()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        AiProviderCredential::factory()->for($workspace)->create();

        $response = $this->get(route('dashboard.ai-providers.index'));

        $response->assertDontSee('sk-ant-', false);
    }

    public function test_store_creates_a_credential_at_the_end_of_the_chain_with_discovered_models()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        AiProviderCredential::factory()->for($workspace)->create(['priority' => 0]);

        Http::fake(['openrouter.ai/*' => Http::response(['data' => [
            ['id' => 'gpt-4o', 'object' => 'model', 'created' => 1715367049],
        ]], 200)]);

        $response = $this->post(route('dashboard.ai-providers.store'), [
            'label' => 'Fallback',
            'provider' => 'openai',
            'base_url' => 'https://openrouter.ai/api',
            'api_key' => 'sk-test-123',
        ]);

        $response->assertRedirect(route('dashboard.ai-providers.index'));

        $stored = AiProviderCredential::where('label', 'Fallback')->sole();
        $this->assertSame($workspace->id, $stored->workspace_id);
        $this->assertSame('openai', $stored->provider);
        $this->assertSame('https://openrouter.ai/api', $stored->base_url);
        $this->assertSame(1, $stored->priority);
        $this->assertTrue($stored->enabled);
        $this->assertSame('sk-test-123', $stored->api_key);
        $this->assertTrue($stored->models()->doesntExist());
        $this->assertSame([['id' => 'gpt-4o', 'label' => 'gpt-4o']], $stored->discovered_models);
        $this->assertNotNull($stored->verified_at);
    }

    public function test_store_rejects_an_unknown_provider()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $this->post(route('dashboard.ai-providers.store'), [
            'label' => 'Bad',
            'provider' => 'made-up',
            'api_key' => 'x',
        ])->assertSessionHasErrors('provider');

        $this->assertSame(0, AiProviderCredential::where('workspace_id', $workspace->id)->count());
    }

    public function test_update_changes_fields_without_requiring_a_new_key()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $credential = AiProviderCredential::factory()->for($workspace)->create([
            'label' => 'Old label',
            'api_key' => 'sk-original',
            'verified_at' => now(),
        ]);

        $response = $this->patch(route('dashboard.ai-providers.update', $credential), [
            'label' => 'New label',
            'base_url' => null,
        ]);

        $response->assertRedirect(route('dashboard.ai-providers.index'));

        $credential->refresh();
        $this->assertSame('New label', $credential->label);
        $this->assertSame('sk-original', $credential->api_key);
        $this->assertNotNull($credential->verified_at);
    }

    public function test_update_replaces_the_key_and_clears_verification_when_a_new_key_is_given()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $credential = AiProviderCredential::factory()->for($workspace)->create([
            'api_key' => 'sk-original',
            'verified_at' => now(),
        ]);

        $this->patch(route('dashboard.ai-providers.update', $credential), [
            'label' => $credential->label,
            'api_key' => 'sk-rotated',
        ]);

        $credential->refresh();
        $this->assertSame('sk-rotated', $credential->api_key);
        $this->assertNull($credential->verified_at);
    }

    public function test_update_404s_for_another_workspaces_credential()
    {
        $this->actingAsWorkspaceMember();
        $other = Workspace::factory()->create();
        $credential = AiProviderCredential::factory()->for($other)->create();

        $this->patch(route('dashboard.ai-providers.update', $credential), [
            'label' => 'Hijacked',
        ])->assertNotFound();
    }

    public function test_destroy_removes_the_credential()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $credential = AiProviderCredential::factory()->for($workspace)->create();

        $this->delete(route('dashboard.ai-providers.destroy', $credential))
            ->assertRedirect(route('dashboard.ai-providers.index'));

        $this->assertDatabaseMissing('ai_provider_credentials', ['id' => $credential->id]);
    }

    public function test_toggle_flips_enabled()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $credential = AiProviderCredential::factory()->for($workspace)->create(['enabled' => true]);

        $this->post(route('dashboard.ai-providers.toggle', $credential));

        $this->assertFalse($credential->fresh()->enabled);
    }

    public function test_reorder_renumbers_priority_in_the_given_order()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $first = AiProviderCredential::factory()->for($workspace)->create(['priority' => 0]);
        $second = AiProviderCredential::factory()->for($workspace)->create(['priority' => 1]);

        $this->post(route('dashboard.ai-providers.reorder'), [
            'ordered_ids' => [$second->id, $first->id],
        ])->assertRedirect(route('dashboard.ai-providers.index'));

        $this->assertSame(0, $second->fresh()->priority);
        $this->assertSame(1, $first->fresh()->priority);
    }

    public function test_reorder_rejects_an_id_from_another_workspace()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $mine = AiProviderCredential::factory()->for($workspace)->create(['priority' => 0]);
        $other = Workspace::factory()->create();
        $notMine = AiProviderCredential::factory()->for($other)->create();

        $this->post(route('dashboard.ai-providers.reorder'), [
            'ordered_ids' => [$notMine->id, $mine->id],
        ])->assertRedirect(route('dashboard.ai-providers.index'));

        $this->assertSame(0, $mine->fresh()->priority);
    }

    public function test_verify_marks_the_credential_verified_on_a_successful_check()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $credential = AiProviderCredential::factory()->for($workspace)->create(['provider' => 'anthropic']);

        Http::fake(['api.anthropic.com/*' => Http::response(['data' => []], 200)]);

        $this->post(route('dashboard.ai-providers.verify', $credential))
            ->assertRedirect(route('dashboard.ai-providers.index'));

        $this->assertNotNull($credential->fresh()->verified_at);
    }

    public function test_verify_does_not_mark_verified_on_a_failed_check()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $credential = AiProviderCredential::factory()->for($workspace)->create(['provider' => 'anthropic']);

        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'invalid key']], 401)]);

        $this->post(route('dashboard.ai-providers.verify', $credential));

        $this->assertNull($credential->fresh()->verified_at);
    }
}
