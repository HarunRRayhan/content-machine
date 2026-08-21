<?php

namespace Tests\Unit\Actions\AiProviders;

use App\Actions\AiProviders\CreateAiProviderCredentialAction;
use App\Data\AiProviders\CreateAiProviderCredentialData;
use App\Models\AiProviderCredential;
use App\Models\Workspace;
use App\Support\AiProviders\AiProviderVerificationResult;
use App\Support\AiProviders\AiProviderVerifierContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAiProviderCredentialActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(AiProviderVerifierContract $verifier): CreateAiProviderCredentialAction
    {
        return new CreateAiProviderCredentialAction($verifier);
    }

    private function alwaysFailingVerifier(): AiProviderVerifierContract
    {
        return new class implements AiProviderVerifierContract
        {
            public function verify(AiProviderCredential $credential): AiProviderVerificationResult
            {
                return AiProviderVerificationResult::failure('unreachable in this test');
            }
        };
    }

    public function test_it_creates_a_credential_with_priority_zero_when_the_chain_is_empty()
    {
        $workspace = Workspace::factory()->create();

        $credential = $this->action($this->alwaysFailingVerifier())->handle($workspace, new CreateAiProviderCredentialData(
            label: 'Primary',
            provider: 'anthropic',
            baseUrl: null,
            apiKey: 'sk-ant-123',
        ));

        $this->assertSame(0, $credential->priority);
        $this->assertTrue($credential->enabled);
        $this->assertSame('sk-ant-123', $credential->api_key);
        $this->assertTrue($credential->models()->doesntExist());
    }

    public function test_it_appends_after_the_existing_highest_priority()
    {
        $workspace = Workspace::factory()->create();
        AiProviderCredential::factory()->for($workspace)->create(['priority' => 5]);

        $credential = $this->action($this->alwaysFailingVerifier())->handle($workspace, new CreateAiProviderCredentialData(
            label: 'Fallback',
            provider: 'openai',
            baseUrl: 'https://openrouter.ai/api',
            apiKey: 'sk-456',
        ));

        $this->assertSame(6, $credential->priority);
    }

    public function test_it_does_not_let_another_workspaces_priority_affect_the_new_number()
    {
        $workspace = Workspace::factory()->create();
        $other = Workspace::factory()->create();
        AiProviderCredential::factory()->for($other)->create(['priority' => 99]);

        $credential = $this->action($this->alwaysFailingVerifier())->handle($workspace, new CreateAiProviderCredentialData(
            label: 'Primary',
            provider: 'anthropic',
            baseUrl: null,
            apiKey: 'sk-ant-123',
        ));

        $this->assertSame(0, $credential->priority);
    }

    public function test_a_successful_check_stores_discovered_models_and_marks_verified()
    {
        $workspace = Workspace::factory()->create();
        $models = [['id' => 'claude-sonnet-4-5', 'label' => 'Claude Sonnet 4.5']];

        $verifier = new class($models) implements AiProviderVerifierContract
        {
            public function __construct(private readonly array $models) {}

            public function verify(AiProviderCredential $credential): AiProviderVerificationResult
            {
                return AiProviderVerificationResult::success($this->models);
            }
        };

        $credential = $this->action($verifier)->handle($workspace, new CreateAiProviderCredentialData(
            label: 'Primary',
            provider: 'anthropic',
            baseUrl: null,
            apiKey: 'sk-ant-123',
        ));

        $this->assertTrue($credential->models()->doesntExist());
        $this->assertSame($models, $credential->discovered_models);
        $this->assertNotNull($credential->verified_at);
    }

    public function test_a_successful_check_with_no_models_listed_still_marks_verified()
    {
        $workspace = Workspace::factory()->create();

        $verifier = new class implements AiProviderVerifierContract
        {
            public function verify(AiProviderCredential $credential): AiProviderVerificationResult
            {
                return AiProviderVerificationResult::success([]);
            }
        };

        $credential = $this->action($verifier)->handle($workspace, new CreateAiProviderCredentialData(
            label: 'Primary',
            provider: 'anthropic',
            baseUrl: null,
            apiKey: 'sk-ant-123',
        ));

        $this->assertSame([], $credential->discovered_models);
        $this->assertNotNull($credential->verified_at);
    }

    public function test_a_failed_check_still_saves_the_credential_unverified_and_undiscovered()
    {
        $workspace = Workspace::factory()->create();

        $credential = $this->action($this->alwaysFailingVerifier())->handle($workspace, new CreateAiProviderCredentialData(
            label: 'Primary',
            provider: 'anthropic',
            baseUrl: null,
            apiKey: 'sk-ant-123',
        ));

        $this->assertTrue($credential->models()->doesntExist());
        $this->assertNull($credential->discovered_models);
        $this->assertNull($credential->verified_at);
        $this->assertSame('sk-ant-123', $credential->api_key);
    }
}
