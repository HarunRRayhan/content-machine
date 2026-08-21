<?php

namespace Tests\Unit\Actions\AiProviders;

use App\Actions\AiProviders\AddAiProviderCredentialModelsAction;
use App\Data\AiProviders\AddAiProviderCredentialModelsData;
use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialModel;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddAiProviderCredentialModelsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_each_selected_model_as_its_own_row()
    {
        $credential = AiProviderCredential::factory()->create();

        (new AddAiProviderCredentialModelsAction)->handle($credential, new AddAiProviderCredentialModelsData(
            models: ['gpt-4o', 'gpt-5.4'],
            purpose: 'default',
        ));

        $entries = $credential->models()->orderBy('priority')->get();
        $this->assertSame(['gpt-4o', 'gpt-5.4'], $entries->pluck('model')->all());
        $this->assertSame([0, 1], $entries->pluck('priority')->all());
        $this->assertSame(['default', 'default'], $entries->pluck('purpose')->all());
    }

    public function test_priority_continues_after_whatever_is_already_in_the_workspaces_chain()
    {
        $workspace = Workspace::factory()->create();
        $existing = AiProviderCredential::factory()->for($workspace)->create();
        AiProviderCredentialModel::factory()->for($existing, 'credential')->create(['priority' => 0]);
        AiProviderCredentialModel::factory()->for($existing, 'credential')->create(['priority' => 1]);

        $credential = AiProviderCredential::factory()->for($workspace)->create();

        (new AddAiProviderCredentialModelsAction)->handle($credential, new AddAiProviderCredentialModelsData(
            models: ['claude-sonnet-4-5'],
            purpose: 'default',
        ));

        $this->assertSame(2, $credential->models()->sole()->priority);
    }

    public function test_purpose_chains_have_independent_priority_numbering()
    {
        $credential = AiProviderCredential::factory()->create();
        AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['priority' => 0, 'purpose' => 'default']);

        (new AddAiProviderCredentialModelsAction)->handle($credential, new AddAiProviderCredentialModelsData(
            models: ['claude-sonnet-4-5'],
            purpose: 'vision',
        ));

        $visionEntry = $credential->models()->where('purpose', 'vision')->sole();
        $this->assertSame(0, $visionEntry->priority);
    }

    public function test_an_already_added_model_for_the_same_purpose_is_not_duplicated()
    {
        $credential = AiProviderCredential::factory()->create();
        AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['model' => 'gpt-4o', 'purpose' => 'default']);

        (new AddAiProviderCredentialModelsAction)->handle($credential, new AddAiProviderCredentialModelsData(
            models: ['gpt-4o', 'gpt-5.4'],
            purpose: 'default',
        ));

        $this->assertSame(['gpt-4o', 'gpt-5.4'], $credential->models()->orderBy('priority')->pluck('model')->all());
    }

    public function test_the_same_model_can_be_added_separately_for_each_purpose()
    {
        $credential = AiProviderCredential::factory()->create();
        AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['model' => 'gpt-4o', 'purpose' => 'default']);

        (new AddAiProviderCredentialModelsAction)->handle($credential, new AddAiProviderCredentialModelsData(
            models: ['gpt-4o'],
            purpose: 'vision',
        ));

        $this->assertSame(2, $credential->models()->count());
    }
}
