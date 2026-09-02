<?php

namespace App\Support\AiProviders;

use App\Models\AiProviderCredential;
use App\Support\LinkResolution\PublicUrlGuard;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Calls OpenAI's audio transcription endpoint directly (whisper-1, the
 * most broadly available and cheapest transcription model), independent
 * of $credential->model: that field is for chat/completion models, a
 * different concern from which model transcribes audio.
 */
final class OpenAiTranscriptionClient implements AiTranscriptionClientContract
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    private const MODEL = 'whisper-1';

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

            $response = Http::withToken($credential->api_key)
                ->timeout(60)
                ->withOptions([
                    'allow_redirects' => false,
                    ...$endpoint['options'],
                ])
                ->attach('file', $audioContents, $filename, ['Content-Type' => $mimeType])
                ->post($endpoint['url'].'/audio/transcriptions', [
                    'model' => self::MODEL,
                    'response_format' => 'verbose_json',
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

        $language = $response->json('language');

        return AiTranscriptionResult::success(
            text: trim($text),
            language: is_string($language) && $language !== '' ? $language : null,
        );
    }
}
