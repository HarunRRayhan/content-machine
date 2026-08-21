<?php

namespace Tests\Feature\AiProviders;

use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialModel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProviderCredentialModelsControllerTest extends TestCase
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

    public function test_store_adds_selected_models_to_the_default_chain()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $credential = AiProviderCredential::factory()->for($workspace)->create([
            'discovered_models' => [
                ['id' => 'gpt-4o', 'label' => 'gpt-4o'],
                ['id' => 'gpt-5.4', 'label' => 'gpt-5.4'],
            ],
        ]);

        $response = $this->post(route('dashboard.ai-provider-models.store', $credential), [
            'models' => ['gpt-4o'],
            'purpose' => 'default',
        ]);

        $response->assertRedirect(route('dashboard.ai-providers.index'));

        $entry = $credential->models()->sole();
        $this->assertSame('gpt-4o', $entry->model);
        $this->assertSame('default', $entry->purpose);
    }

    public function test_store_404s_for_another_workspaces_credential()
    {
        $this->actingAsWorkspaceMember();
        $other = Workspace::factory()->create();
        $credential = AiProviderCredential::factory()->for($other)->create([
            'discovered_models' => [['id' => 'gpt-4o', 'label' => 'gpt-4o']],
        ]);

        $this->post(route('dashboard.ai-provider-models.store', $credential), [
            'models' => ['gpt-4o'],
            'purpose' => 'default',
        ])->assertNotFound();
    }

    public function test_destroy_removes_the_entry()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $credential = AiProviderCredential::factory()->for($workspace)->create();
        $entry = AiProviderCredentialModel::factory()->for($credential, 'credential')->create();

        $this->delete(route('dashboard.ai-provider-models.destroy', $entry))
            ->assertRedirect(route('dashboard.ai-providers.index'));

        $this->assertDatabaseMissing('ai_provider_credential_models', ['id' => $entry->id]);
    }

    public function test_destroy_404s_for_another_workspaces_entry()
    {
        $this->actingAsWorkspaceMember();
        $other = Workspace::factory()->create();
        $otherCredential = AiProviderCredential::factory()->for($other)->create();
        $entry = AiProviderCredentialModel::factory()->for($otherCredential, 'credential')->create();

        $this->delete(route('dashboard.ai-provider-models.destroy', $entry))
            ->assertNotFound();
    }

    public function test_reorder_renumbers_priority_in_the_given_order()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $credential = AiProviderCredential::factory()->for($workspace)->create();
        $first = AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['priority' => 0]);
        $second = AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['priority' => 1]);

        $this->post(route('dashboard.ai-provider-models.reorder'), [
            'purpose' => 'default',
            'ordered_ids' => [$second->id, $first->id],
        ])->assertRedirect(route('dashboard.ai-providers.index'));

        $this->assertSame(0, $second->fresh()->priority);
        $this->assertSame(1, $first->fresh()->priority);
    }

    public function test_reorder_rejects_an_id_from_another_workspace()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $credential = AiProviderCredential::factory()->for($workspace)->create();
        $mine = AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['priority' => 0]);

        $other = Workspace::factory()->create();
        $otherCredential = AiProviderCredential::factory()->for($other)->create();
        $notMine = AiProviderCredentialModel::factory()->for($otherCredential, 'credential')->create();

        $this->post(route('dashboard.ai-provider-models.reorder'), [
            'purpose' => 'default',
            'ordered_ids' => [$notMine->id, $mine->id],
        ])->assertRedirect(route('dashboard.ai-providers.index'));

        $this->assertSame(0, $mine->fresh()->priority);
    }
}
