<?php

namespace App\Data\Scratchpad;

use App\Http\Requests\Scratchpad\StoreScratchpadVoiceRequest;
use Illuminate\Http\UploadedFile;

/**
 * Typed input for CaptureScratchpadVoiceAction. $language is captured at
 * upload time so transcription can backfill it only when it was not supplied.
 * $source defaults to 'web', see CaptureTextNoteData's docblock for why.
 */
final readonly class CaptureScratchpadVoiceData
{
    public function __construct(
        public UploadedFile $file,
        public ?string $language,
        public string $source = 'web',
        public ?int $telegramChatId = null,
        public ?string $caption = null,
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

    public static function fromTelegram(UploadedFile $file, int $telegramChatId, ?string $caption = null): self
    {
        return new self(file: $file, language: null, source: 'telegram', telegramChatId: $telegramChatId, caption: $caption);
    }

    public static function fromApi(UploadedFile $file, ?string $language): self
    {
        return new self(file: $file, language: $language, source: 'api');
    }
}
