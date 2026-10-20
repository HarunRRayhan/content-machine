<?php

namespace App\Support\AiProviders;

use App\Models\AiProviderCredential;
use App\Support\LinkResolution\PublicUrlGuard;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Calls OpenAI's audio transcription endpoint directly, independent of
 * $credential->model: that field is for chat/completion models, a
 * different concern from which model transcribes audio.
 *
 * Tries gpt-4o-transcribe first (much better at Bangla), then falls back
 * to whisper-1 on the same credential when that call fails (non-2xx,
 * network error, or empty text). gpt-4o-transcribe only accepts
 * response_format=json and reports no language, so its language is
 * inferred from the script ("bengali" or "english", whisper's naming).
 * If both fail, the first model's error is returned.
 */
final class OpenAiTranscriptionClient implements AiTranscriptionClientContract
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    /** Model => response_format, in the order they are tried. */
    private const MODELS = [
        'gpt-4o-transcribe' => 'json',
        'whisper-1' => 'verbose_json',
    ];

    public function __construct(
        private readonly ?PublicUrlGuard $urlGuard = null,
    ) {}

    public function transcribe(AiProviderCredential $credential, string $audioContents, string $filename, string $mimeType): AiTranscriptionResult
    {
        try {
            // See HttpAiProviderVerifier::verifyOpenAi() for why this is a bare
            // "/audio/transcriptions": the base URL already carries the version.
            $endpoint = (new AiProviderBaseUrl($this->urlGuard))->resolve(
                $credential->base_url,
                self::DEFAULT_BASE_URL,
            );
        } catch (Throwable) {
            return AiTranscriptionResult::failure('Could not reach the transcription provider.');
        }

        $firstFailure = null;

        foreach (self::MODELS as $model => $format) {
            $result = $this->attempt($credential, $endpoint, $model, $format, $audioContents, $filename, $mimeType);

            if ($result->successful) {
                return $result;
            }

            $firstFailure ??= $result;
        }

        return $firstFailure;
    }

    /**
     * @param  array{url: string, options: array<string, mixed>}  $endpoint
     */
    private function attempt(AiProviderCredential $credential, array $endpoint, string $model, string $format, string $audioContents, string $filename, string $mimeType): AiTranscriptionResult
    {
        try {
            $response = Http::withToken($credential->api_key)
                ->timeout(60)
                ->withOptions([
                    'allow_redirects' => false,
                    ...$endpoint['options'],
                ])
                ->attach('file', $audioContents, $filename, ['Content-Type' => $mimeType])
                ->post($endpoint['url'].'/audio/transcriptions', [
                    'model' => $model,
                    'response_format' => $format,
                ]);
        } catch (Throwable) {
            return AiTranscriptionResult::failure('Could not reach the transcription provider.');
        }

        if (! $response->successful()) {
            $message = $response->json('error.message');

            return AiTranscriptionResult::failure(
                is_string($message) && $message !== '' ? $message : "The transcription provider returned an unexpected status ({$response->status()})."
            );
        }

        $text = $response->json('text');

        if (! is_string($text) || trim($text) === '') {
            return AiTranscriptionResult::failure('The transcription provider returned no text.');
        }

        $text = trim($text);
        $language = $response->json('language');

        if ($format === 'json') {
            $language = preg_match('/[\x{0980}-\x{09FF}]/u', $text) === 1 ? 'bengali' : 'english';
        }

        return AiTranscriptionResult::success(
            text: $text,
            language: is_string($language) && $language !== '' ? $language : null,
            model: $model,
        );
    }
}
