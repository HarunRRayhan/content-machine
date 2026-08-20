<?php

namespace App\Support\AiProviders;

use App\Models\AiProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Runs a single chat completion against whichever provider shape the
 * credential is (Anthropic Messages API or OpenAI-compatible Chat
 * Completions), the same provider branching HttpAiProviderVerifier
 * already does for key verification.
 */
final class HttpAiCompletionClient implements AiCompletionClientContract
{
    private const ANTHROPIC_DEFAULT_BASE_URL = 'https://api.anthropic.com';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const OPENAI_DEFAULT_BASE_URL = 'https://api.openai.com';

    private const MAX_TOKENS = 300;

    public function complete(AiProviderCredential $credential, string $systemPrompt, string $userContent): AiCompletionResult
    {
        try {
            $response = $credential->provider === 'anthropic'
                ? $this->completeAnthropic($credential, $systemPrompt, $userContent)
                : $this->completeOpenAi($credential, $systemPrompt, $userContent);
        } catch (Throwable) {
            return AiCompletionResult::failure('Could not reach the completion provider.');
        }

        if (! $response->successful()) {
            $message = $response->json('error.message');

            return AiCompletionResult::failure(
                is_string($message) && $message !== '' ? $message : "The completion provider returned an unexpected status ({$response->status()})."
            );
        }

        $text = $credential->provider === 'anthropic'
            ? $response->json('content.0.text')
            : $response->json('choices.0.message.content');

        if (! is_string($text) || trim($text) === '') {
            return AiCompletionResult::failure('The completion provider returned no text.');
        }

        return AiCompletionResult::success(trim($text));
    }

    private function completeAnthropic(AiProviderCredential $credential, string $systemPrompt, string $userContent): Response
    {
        $baseUrl = rtrim($credential->base_url ?? self::ANTHROPIC_DEFAULT_BASE_URL, '/');

        return Http::withHeaders([
            'x-api-key' => $credential->api_key,
            'anthropic-version' => self::ANTHROPIC_VERSION,
        ])->timeout(30)->post("{$baseUrl}/v1/messages", [
            'model' => $credential->model,
            'max_tokens' => self::MAX_TOKENS,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userContent],
            ],
        ]);
    }

    private function completeOpenAi(AiProviderCredential $credential, string $systemPrompt, string $userContent): Response
    {
        $baseUrl = rtrim($credential->base_url ?? self::OPENAI_DEFAULT_BASE_URL, '/');

        return Http::withToken($credential->api_key)
            ->timeout(30)
            ->post("{$baseUrl}/v1/chat/completions", [
                'model' => $credential->model,
                'max_tokens' => self::MAX_TOKENS,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent],
                ],
            ]);
    }
}
