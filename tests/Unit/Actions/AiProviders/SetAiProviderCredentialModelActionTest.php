<?php

namespace Tests\Unit\Actions\AiProviders;

use App\Actions\AiProviders\SetAiProviderCredentialModelAction;
use App\Models\AiProviderCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetAiProviderCredentialModelActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_the_model_and_clears_discovered_models()
    {
        $credential = AiProviderCredential::factory()->withoutModel()->create([
            'discovered_models' => [['id' => 'gpt-4o', 'label' => 'gpt-4o']],
        ]);

        (new SetAiProviderCredentialModelAction)->handle($credential, 'gpt-4o');

        $credential->refresh();
        $this->assertSame('gpt-4o', $credential->model);
        $this->assertNull($credential->discovered_models);
    }

    public function test_it_can_replace_an_already_set_model()
    {
        $credential = AiProviderCredential::factory()->create(['model' => 'gpt-4o']);

        (new SetAiProviderCredentialModelAction)->handle($credential, 'gpt-5.4');

        $this->assertSame('gpt-5.4', $credential->fresh()->model);
    }
}
