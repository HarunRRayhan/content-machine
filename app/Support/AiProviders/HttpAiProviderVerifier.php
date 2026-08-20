<?php

namespace App\Support\AiProviders;

use App\Models\AiProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Confirms a stored key actually authenticates, by calling each provider's
 * cheapest real endpoint (list models) rather than spending on a
 * completion. This never sends the credential anywhere but the provider's
 * own API: no third-party validation service, no logging of the key.
 */
final class HttpAiProviderVerifier implements AiProviderVerifierContract
{
    private const ANTHROPIC_DEFAULT_BASE_URL = 'https://api.anthropic.com';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const OPENAI_DEFAULT_BASE_URL = 'https://api.openai.com';

    public function verify(AiProviderCredential $credential): AiProviderVerificationResult
    {
        try {
            $response = $credential->provider === 'anthropic'
                ? $this->verifyAnthropic($credential)
                : $this->verifyOpenAi($credential);
        } catch (Throwable) {
            return AiProviderVerificationResult::failure('Could not reach the provider. Check the base URL and your network.');
        }

        if ($response->successful()) {
            return AiProviderVerificationResult::success();
        }

        return AiProviderVerificationResult::failure($this->describeFailure($response));
    }

    private function verifyAnthropic(AiProviderCredential $credential): Response
    {
        $baseUrl = rtrim($credential->base_url ?? self::ANTHROPIC_DEFAULT_BASE_URL, '/');

        return Http::withHeaders([
            'x-api-key' => $credential->api_key,
            'anthropic-version' => self::ANTHROPIC_VERSION,
        ])->timeout(10)->get("{$baseUrl}/v1/models");
    }

    private function verifyOpenAi(AiProviderCredential $credential): Response
    {
        $baseUrl = rtrim($credential->base_url ?? self::OPENAI_DEFAULT_BASE_URL, '/');

        return Http::withToken($credential->api_key)
            ->timeout(10)
            ->get("{$baseUrl}/v1/models");
    }

    private function describeFailure(Response $response): string
    {
        if ($response->status() === 401 || $response->status() === 403) {
            return 'The provider rejected this key as invalid.';
        }

        $providerMessage = $response->json('error.message');

        if (is_string($providerMessage) && $providerMessage !== '') {
            return $providerMessage;
        }

        return "The provider returned an unexpected status ({$response->status()}).";
    }
}
