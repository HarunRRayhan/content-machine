<?php

namespace App\Support\AiProviders;

use App\Models\AiProviderCredential;
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
    private const DEFAULT_BASE_URL = 'https://api.openai.com';

    private const MODEL = 'whisper-1';

    public function transcribe(AiProviderCredential $credential, string $audioContents, string $filename, string $mimeType): AiTranscriptionResult
    {
        $baseUrl = rtrim($credential->base_url ?? self::DEFAULT_BASE_URL, '/');

        try {
            $response = Http::withToken($credential->api_key)
                ->timeout(60)
                ->attach('file', $audioContents, $filename, ['Content-Type' => $mimeType])
                ->post("{$baseUrl}/v1/audio/transcriptions", [
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
