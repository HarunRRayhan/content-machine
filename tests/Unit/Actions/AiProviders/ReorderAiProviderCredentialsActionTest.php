<?php

namespace Tests\Unit\Actions\AiProviders;

use App\Actions\AiProviders\ReorderAiProviderCredentialsAction;
use App\Data\AiProviders\ReorderAiProviderCredentialsData;
use App\Models\AiProviderCredential;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReorderAiProviderCredentialsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renumbers_priority_to_match_the_given_order()
    {
        $workspace = Workspace::factory()->create();
        $a = AiProviderCredential::factory()->for($workspace)->create(['priority' => 0]);
        $b = AiProviderCredential::factory()->for($workspace)->create(['priority' => 1]);
        $c = AiProviderCredential::factory()->for($workspace)->create(['priority' => 2]);

        (new ReorderAiProviderCredentialsAction)->handle($workspace, new ReorderAiProviderCredentialsData(
            orderedIds: [$c->id, $a->id, $b->id],
        ));

        $this->assertSame(0, $c->fresh()->priority);
        $this->assertSame(1, $a->fresh()->priority);
        $this->assertSame(2, $b->fresh()->priority);
    }

    public function test_it_rejects_an_id_that_is_not_this_workspaces_own()
    {
        $workspace = Workspace::factory()->create();
        $mine = AiProviderCredential::factory()->for($workspace)->create(['priority' => 0]);
        $notMine = AiProviderCredential::factory()->create();

        $this->expectException(RuntimeException::class);

        (new ReorderAiProviderCredentialsAction)->handle($workspace, new ReorderAiProviderCredentialsData(
            orderedIds: [$notMine->id, $mine->id],
        ));
    }

    public function test_it_rejects_a_partial_list_missing_one_of_the_workspaces_credentials()
    {
        $workspace = Workspace::factory()->create();
        $a = AiProviderCredential::factory()->for($workspace)->create(['priority' => 0]);
        AiProviderCredential::factory()->for($workspace)->create(['priority' => 1]);

        $this->expectException(RuntimeException::class);

        (new ReorderAiProviderCredentialsAction)->handle($workspace, new ReorderAiProviderCredentialsData(
            orderedIds: [$a->id],
        ));
    }
}
