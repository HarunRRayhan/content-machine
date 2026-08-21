<?php

namespace Tests\Unit\Actions\AiProviders;

use App\Actions\AiProviders\VerifyAiProviderCredentialAction;
use App\Models\AiProviderCredential;
use App\Support\AiProviders\AiProviderVerificationResult;
use App\Support\AiProviders\AiProviderVerifierContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifyAiProviderCredentialActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_verification_stamps_verified_at()
    {
        $credential = AiProviderCredential::factory()->create(['verified_at' => null]);

        $verifier = new class implements AiProviderVerifierContract
        {
            public function verify(AiProviderCredential $credential): AiProviderVerificationResult
            {
                return AiProviderVerificationResult::success();
            }
        };

        $result = (new VerifyAiProviderCredentialAction($verifier))->handle($credential);

        $this->assertTrue($result->successful);
        $this->assertNotNull($credential->fresh()->verified_at);
    }

    public function test_a_successful_verification_refreshes_discovered_models_even_when_a_model_is_already_set()
    {
        $credential = AiProviderCredential::factory()->create([
            'model' => 'gpt-4o',
            'discovered_models' => null,
        ]);

        $verifier = new class implements AiProviderVerifierContract
        {
            public function verify(AiProviderCredential $credential): AiProviderVerificationResult
            {
                return AiProviderVerificationResult::success([
                    ['id' => 'gpt-5.4', 'label' => 'gpt-5.4'],
                    ['id' => 'gpt-4o', 'label' => 'gpt-4o'],
                ]);
            }
        };

        (new VerifyAiProviderCredentialAction($verifier))->handle($credential);

        $this->assertSame([
            ['id' => 'gpt-5.4', 'label' => 'gpt-5.4'],
            ['id' => 'gpt-4o', 'label' => 'gpt-4o'],
        ], $credential->fresh()->discovered_models);
        $this->assertSame('gpt-4o', $credential->fresh()->model);
    }

    public function test_a_failed_verification_leaves_verified_at_null_and_returns_the_error()
    {
        $credential = AiProviderCredential::factory()->create(['verified_at' => null]);

        $verifier = new class implements AiProviderVerifierContract
        {
            public function verify(AiProviderCredential $credential): AiProviderVerificationResult
            {
                return AiProviderVerificationResult::failure('The provider rejected this key as invalid.');
            }
        };

        $result = (new VerifyAiProviderCredentialAction($verifier))->handle($credential);

        $this->assertFalse($result->successful);
        $this->assertSame('The provider rejected this key as invalid.', $result->error);
        $this->assertNull($credential->fresh()->verified_at);
    }
}
