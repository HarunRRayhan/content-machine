<?php

namespace App\Data\Scratchpad;

use App\Http\Requests\Scratchpad\StoreScratchpadVoiceRequest;
use Illuminate\Http\UploadedFile;

/**
 * Typed input for CaptureScratchpadVoiceAction. $language is captured now
 * (rather than left for a later migration) purely so a future transcription
 * slice doesn't need one just to add this column; no transcription happens
 * in this phase. $source defaults to 'web', see CaptureTextNoteData's
 * docblock for why.
 */
final readonly class CaptureScratchpadVoiceData
{
    public function __construct(
        public UploadedFile $file,
        public ?string $language,
        public string $source = 'web',
    ) {}

    public static function fromRequest(StoreScratchpadVoiceRequest $request): self
    {
        /** @var UploadedFile $file */
        $file = $request->file('audio');

        return new self(
            file: $file,
            language: $request->string('language')->toString() ?: null,
            source: 'web',
        );
    }

    public static function fromTelegram(UploadedFile $file): self
    {
        return new self(file: $file, language: null, source: 'telegram');
    }
}
