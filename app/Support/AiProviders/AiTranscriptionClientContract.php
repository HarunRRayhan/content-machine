<?php

namespace App\Support\AiProviders;

use App\Models\AiProviderCredential;

interface AiTranscriptionClientContract
{
    /**
     * Transcribes raw audio bytes using $credential, which must be an
     * openai-shaped credential (audio transcription has no anthropic-shaped
     * equivalent) — callers are responsible for that filtering, this
     * method doesn't re-check $credential->provider itself.
     */
    public function transcribe(AiProviderCredential $credential, string $audioContents, string $filename, string $mimeType): AiTranscriptionResult;
}
