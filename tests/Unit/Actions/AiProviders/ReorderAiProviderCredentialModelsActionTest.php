<?php

namespace Tests\Unit\Actions\AiProviders;

use App\Actions\AiProviders\ReorderAiProviderCredentialModelsAction;
use App\Data\AiProviders\ReorderAiProviderCredentialModelsData;
use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialModel;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReorderAiProviderCredentialModelsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renumbers_priority_in_the_given_order()
    {
        $workspace = Workspace::factory()->create();
        $credential = AiProviderCredential::factory()->for($workspace)->create();
        $first = AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['priority' => 0]);
        $second = AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['priority' => 1]);

        (new ReorderAiProviderCredentialModelsAction)->handle($workspace, new ReorderAiProviderCredentialModelsData(
            purpose: 'default',
            orderedIds: [$second->id, $first->id],
        ));

        $this->assertSame(0, $second->fresh()->priority);
        $this->assertSame(1, $first->fresh()->priority);
    }

    public function test_it_rejects_an_id_from_another_workspace()
    {
        $workspace = Workspace::factory()->create();
        $credential = AiProviderCredential::factory()->for($workspace)->create();
        $mine = AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['priority' => 0]);

        $other = Workspace::factory()->create();
        $otherCredential = AiProviderCredential::factory()->for($other)->create();
        $notMine = AiProviderCredentialModel::factory()->for($otherCredential, 'credential')->create();

        $this->expectException(RuntimeException::class);

        (new ReorderAiProviderCredentialModelsAction)->handle($workspace, new ReorderAiProviderCredentialModelsData(
            purpose: 'default',
            orderedIds: [$notMine->id, $mine->id],
        ));
    }

    public function test_it_rejects_an_incomplete_order()
    {
        $workspace = Workspace::factory()->create();
        $credential = AiProviderCredential::factory()->for($workspace)->create();
        $first = AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['priority' => 0]);
        AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['priority' => 1]);

        $this->expectException(RuntimeException::class);

        (new ReorderAiProviderCredentialModelsAction)->handle($workspace, new ReorderAiProviderCredentialModelsData(
            purpose: 'default',
            orderedIds: [$first->id],
        ));
    }
}
