<?php

namespace App\Support\AiProviders;

use App\Models\AiProviderCredential;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs a single chat completion against whichever provider shape the
 * credential is (Anthropic Messages API or OpenAI-compatible Chat
 * Completions), the same provider branching HttpAiProviderVerifier
 * already does for key verification.
 *
 * Every failure is logged (credential id/provider/model, never the key)
 * before returning: AiCompletionResult::$error only ever reaches the
 * user as a generic fallback message (see GenerateTelegramChatReplyAction,
 * ResolveTelegramIntentAction), so `railway logs` is the only place the
 * actual provider error is visible for diagnosing a "why isn't AI chat
 * replying" report.
 */
final class HttpAiCompletionClient implements AiCompletionClientContract
{
    private const ANTHROPIC_DEFAULT_BASE_URL = 'https://api.anthropic.com';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const OPENAI_DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    private const MAX_TOKENS = 300;

    public function complete(AiProviderCredential $credential, string $systemPrompt, string $userContent): AiCompletionResult
    {
        // Guaranteed non-null: AiProviderCredentialResolver::chain() only
        // ever hands out credentials with a model already set. Narrowed
        // here, once, so completeAnthropic()/completeOpenAi() below get a
        // definite string rather than each re-checking $credential->model.
        if ($credential->model === null) {
            return AiCompletionResult::failure('This credential has no model set yet.');
        }

        $model = $credential->model;

        try {
            $response = $credential->provider === 'anthropic'
                ? $this->completeAnthropic($credential, $model, $systemPrompt, $userContent)
                : $this->completeOpenAi($credential, $model, $systemPrompt, $userContent);
        } catch (Throwable $e) {
            $this->logFailure($credential, $model, 'connection', $e->getMessage());

            return AiCompletionResult::failure('Could not reach the completion provider.');
        }

        if (! $response->successful()) {
            $message = $response->json('error.message');
            $error = is_string($message) && $message !== '' ? $message : "The completion provider returned an unexpected status ({$response->status()}).";

            $this->logFailure($credential, $model, (string) $response->status(), $error);

            return AiCompletionResult::failure($error);
        }

        $text = $credential->provider === 'anthropic'
            ? $response->json('content.0.text')
            : $response->json('choices.0.message.content');

        if (! is_string($text) || trim($text) === '') {
            $this->logFailure($credential, $model, (string) $response->status(), 'no text in a successful response');

            return AiCompletionResult::failure('The completion provider returned no text.');
        }

        return AiCompletionResult::success(trim($text));
    }

    private function logFailure(AiProviderCredential $credential, string $model, string $status, string $reason): void
    {
        Log::warning('AI completion failed', [
            'credential_id' => $credential->id,
            'provider' => $credential->provider,
            'model' => $model,
            'status' => $status,
            'reason' => $reason,
        ]);
    }

    private function completeAnthropic(AiProviderCredential $credential, string $model, string $systemPrompt, string $userContent): Response
    {
        $baseUrl = rtrim($credential->base_url ?? self::ANTHROPIC_DEFAULT_BASE_URL, '/');

        return Http::withHeaders([
            'x-api-key' => $credential->api_key,
            'anthropic-version' => self::ANTHROPIC_VERSION,
        ])->timeout(30)->post("{$baseUrl}/v1/messages", [
            'model' => $model,
            'max_tokens' => self::MAX_TOKENS,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userContent],
            ],
        ]);
    }

    private function completeOpenAi(AiProviderCredential $credential, string $model, string $systemPrompt, string $userContent): Response
    {
        // See HttpAiProviderVerifier::verifyOpenAi() for why this is a bare
        // "/chat/completions": the base URL already carries the version.
        $baseUrl = rtrim($credential->base_url ?? self::OPENAI_DEFAULT_BASE_URL, '/');

        return Http::withToken($credential->api_key)
            ->timeout(30)
            ->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'max_tokens' => self::MAX_TOKENS,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent],
                ],
            ]);
    }
}
