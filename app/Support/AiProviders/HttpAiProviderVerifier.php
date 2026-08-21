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

    private const OPENAI_DEFAULT_BASE_URL = 'https://api.openai.com/v1';

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
            return AiProviderVerificationResult::success($this->parseModels($credential->provider, $response));
        }

        return AiProviderVerificationResult::failure($this->describeFailure($response));
    }

    /**
     * @return array<int, array{id: string, label: string}>
     */
    private function parseModels(string $provider, Response $response): array
    {
        $data = $response->json('data');

        if (! is_array($data)) {
            return [];
        }

        $models = [];

        foreach ($data as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                continue;
            }

            $label = $provider === 'anthropic' && is_string($item['display_name'] ?? null)
                ? $item['display_name']
                : $item['id'];

            $models[] = ['id' => $item['id'], 'label' => $label, 'created' => is_int($item['created'] ?? null) ? $item['created'] : null];
        }

        // Anthropic already lists most-recently-released first; OpenAI's
        // list has no guaranteed order, so sort it by creation time,
        // newest first, to put likely-relevant models near the top of a
        // list that can otherwise run to dozens of entries.
        if ($provider !== 'anthropic') {
            usort($models, fn (array $a, array $b) => ($b['created'] ?? 0) <=> ($a['created'] ?? 0));
        }

        return array_map(fn (array $model) => ['id' => $model['id'], 'label' => $model['label']], $models);
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
        // OpenAI-compatible base URLs conventionally already include the
        // version segment (https://api.openai.com/v1, .../openai/v1 for
        // Groq, https://openrouter.ai/api/v1, ...), so the models endpoint
        // is a bare "/models" rather than another "/v1/models".
        $baseUrl = rtrim($credential->base_url ?? self::OPENAI_DEFAULT_BASE_URL, '/');

        return Http::withToken($credential->api_key)
            ->timeout(10)
            ->get("{$baseUrl}/models");
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
