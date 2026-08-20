<?php

namespace Tests\Unit\Actions\AiProviders;

use App\Actions\AiProviders\CreateAiProviderCredentialAction;
use App\Data\AiProviders\CreateAiProviderCredentialData;
use App\Models\AiProviderCredential;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAiProviderCredentialActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_credential_with_priority_zero_when_the_chain_is_empty()
    {
        $workspace = Workspace::factory()->create();

        $credential = (new CreateAiProviderCredentialAction)->handle($workspace, new CreateAiProviderCredentialData(
            label: 'Primary',
            provider: 'anthropic',
            baseUrl: null,
            model: 'claude-sonnet-4-5',
            apiKey: 'sk-ant-123',
        ));

        $this->assertSame(0, $credential->priority);
        $this->assertTrue($credential->enabled);
        $this->assertSame('sk-ant-123', $credential->api_key);
    }

    public function test_it_appends_after_the_existing_highest_priority()
    {
        $workspace = Workspace::factory()->create();
        AiProviderCredential::factory()->for($workspace)->create(['priority' => 5]);

        $credential = (new CreateAiProviderCredentialAction)->handle($workspace, new CreateAiProviderCredentialData(
            label: 'Fallback',
            provider: 'openai',
            baseUrl: 'https://openrouter.ai/api',
            model: 'gpt-4o',
            apiKey: 'sk-456',
        ));

        $this->assertSame(6, $credential->priority);
    }

    public function test_it_does_not_let_another_workspaces_priority_affect_the_new_number()
    {
        $workspace = Workspace::factory()->create();
        $other = Workspace::factory()->create();
        AiProviderCredential::factory()->for($other)->create(['priority' => 99]);

        $credential = (new CreateAiProviderCredentialAction)->handle($workspace, new CreateAiProviderCredentialData(
            label: 'Primary',
            provider: 'anthropic',
            baseUrl: null,
            model: 'claude-sonnet-4-5',
            apiKey: 'sk-ant-123',
        ));

        $this->assertSame(0, $credential->priority);
    }
}
