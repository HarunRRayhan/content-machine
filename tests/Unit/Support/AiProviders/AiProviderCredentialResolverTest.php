<?php

namespace Tests\Unit\Support\AiProviders;

use App\Models\AiProviderCredential;
use App\Models\Workspace;
use App\Support\AiProviders\AiProviderCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProviderCredentialResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_chain_returns_enabled_credentials_in_priority_order()
    {
        $workspace = Workspace::factory()->create();
        $second = AiProviderCredential::factory()->for($workspace)->create(['priority' => 1, 'label' => 'Second']);
        $first = AiProviderCredential::factory()->for($workspace)->create(['priority' => 0, 'label' => 'First']);

        $chain = (new AiProviderCredentialResolver)->chain($workspace);

        $this->assertSame([$first->id, $second->id], $chain->pluck('id')->all());
    }

    public function test_chain_excludes_disabled_credentials()
    {
        $workspace = Workspace::factory()->create();
        AiProviderCredential::factory()->for($workspace)->disabled()->create(['priority' => 0]);
        $enabled = AiProviderCredential::factory()->for($workspace)->create(['priority' => 1]);

        $chain = (new AiProviderCredentialResolver)->chain($workspace);

        $this->assertSame([$enabled->id], $chain->pluck('id')->all());
    }

    public function test_chain_excludes_credentials_with_no_model_set_yet()
    {
        $workspace = Workspace::factory()->create();
        AiProviderCredential::factory()->for($workspace)->withoutModel()->create(['priority' => 0]);
        $withModel = AiProviderCredential::factory()->for($workspace)->create(['priority' => 1]);

        $chain = (new AiProviderCredentialResolver)->chain($workspace);

        $this->assertSame([$withModel->id], $chain->pluck('id')->all());
    }

    public function test_chain_excludes_another_workspaces_credentials()
    {
        $workspace = Workspace::factory()->create();
        $other = Workspace::factory()->create();
        AiProviderCredential::factory()->for($other)->create();
        $mine = AiProviderCredential::factory()->for($workspace)->create();

        $chain = (new AiProviderCredentialResolver)->chain($workspace);

        $this->assertSame([$mine->id], $chain->pluck('id')->all());
    }

    public function test_default_returns_the_first_in_the_chain_or_null()
    {
        $workspace = Workspace::factory()->create();

        $this->assertNull((new AiProviderCredentialResolver)->default($workspace));

        $first = AiProviderCredential::factory()->for($workspace)->create(['priority' => 0]);
        AiProviderCredential::factory()->for($workspace)->create(['priority' => 1]);

        $this->assertTrue((new AiProviderCredentialResolver)->default($workspace)->is($first));
    }
}
