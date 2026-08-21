<?php

namespace Tests\Unit\Support\AiProviders;

use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialModel;
use App\Models\Workspace;
use App\Support\AiProviders\AiProviderCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProviderCredentialResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_chain_returns_default_purpose_models_in_priority_order()
    {
        $workspace = Workspace::factory()->create();
        $credential = AiProviderCredential::factory()->for($workspace)->create();
        $second = AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['priority' => 1, 'model' => 'second']);
        $first = AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['priority' => 0, 'model' => 'first']);

        $chain = (new AiProviderCredentialResolver)->chain($workspace);

        $this->assertSame([$first->id, $second->id], $chain->pluck('id')->all());
    }

    public function test_chain_only_returns_the_requested_purpose()
    {
        $workspace = Workspace::factory()->create();
        $credential = AiProviderCredential::factory()->for($workspace)->create();
        $vision = AiProviderCredentialModel::factory()->for($credential, 'credential')->vision()->create();
        $default = AiProviderCredentialModel::factory()->for($credential, 'credential')->create();

        $this->assertSame([$default->id], (new AiProviderCredentialResolver)->chain($workspace, 'default')->pluck('id')->all());
        $this->assertSame([$vision->id], (new AiProviderCredentialResolver)->chain($workspace, 'vision')->pluck('id')->all());
    }

    public function test_chain_excludes_models_on_disabled_credentials()
    {
        $workspace = Workspace::factory()->create();
        $disabled = AiProviderCredential::factory()->for($workspace)->disabled()->create();
        AiProviderCredentialModel::factory()->for($disabled, 'credential')->create();
        $enabled = AiProviderCredential::factory()->for($workspace)->create();
        $entry = AiProviderCredentialModel::factory()->for($enabled, 'credential')->create();

        $chain = (new AiProviderCredentialResolver)->chain($workspace);

        $this->assertSame([$entry->id], $chain->pluck('id')->all());
    }

    public function test_chain_excludes_another_workspaces_models()
    {
        $workspace = Workspace::factory()->create();
        $other = Workspace::factory()->create();
        $otherCredential = AiProviderCredential::factory()->for($other)->create();
        AiProviderCredentialModel::factory()->for($otherCredential, 'credential')->create();
        $mineCredential = AiProviderCredential::factory()->for($workspace)->create();
        $mine = AiProviderCredentialModel::factory()->for($mineCredential, 'credential')->create();

        $chain = (new AiProviderCredentialResolver)->chain($workspace);

        $this->assertSame([$mine->id], $chain->pluck('id')->all());
    }

    public function test_text_chain_tries_default_models_before_vision_models()
    {
        $workspace = Workspace::factory()->create();
        $credential = AiProviderCredential::factory()->for($workspace)->create();
        $vision = AiProviderCredentialModel::factory()->for($credential, 'credential')->vision()->create(['priority' => 0]);
        $default = AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['priority' => 0]);

        $chain = (new AiProviderCredentialResolver)->textChain($workspace);

        $this->assertSame([$default->id, $vision->id], $chain->pluck('id')->all());
    }

    public function test_a_credential_with_no_models_added_contributes_nothing()
    {
        $workspace = Workspace::factory()->create();
        AiProviderCredential::factory()->for($workspace)->create();

        $chain = (new AiProviderCredentialResolver)->chain($workspace);

        $this->assertTrue($chain->isEmpty());
    }

    public function test_default_returns_the_first_of_the_text_chain_or_null()
    {
        $workspace = Workspace::factory()->create();

        $this->assertNull((new AiProviderCredentialResolver)->default($workspace));

        $credential = AiProviderCredential::factory()->for($workspace)->create();
        $first = AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['priority' => 0]);
        AiProviderCredentialModel::factory()->for($credential, 'credential')->create(['priority' => 1]);

        $this->assertTrue((new AiProviderCredentialResolver)->default($workspace)->is($first));
    }

    public function test_credential_chain_returns_enabled_credentials_in_priority_order_regardless_of_models()
    {
        $workspace = Workspace::factory()->create();
        $second = AiProviderCredential::factory()->for($workspace)->create(['priority' => 1]);
        $first = AiProviderCredential::factory()->for($workspace)->create(['priority' => 0]);
        AiProviderCredential::factory()->for($workspace)->disabled()->create(['priority' => 2]);

        $chain = (new AiProviderCredentialResolver)->credentialChain($workspace);

        $this->assertSame([$first->id, $second->id], $chain->pluck('id')->all());
    }
}
