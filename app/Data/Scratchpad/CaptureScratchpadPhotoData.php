<?php

namespace App\Data\Scratchpad;

use App\Http\Requests\Scratchpad\StoreScratchpadPhotoRequest;
use Illuminate\Http\UploadedFile;

/**
 * Typed input for CaptureScratchpadPhotoAction. $source defaults to 'web',
 * see CaptureTextNoteData's docblock for why.
 */
final readonly class CaptureScratchpadPhotoData
{
    public function __construct(
        public UploadedFile $file,
        public ?string $caption,
        public string $source = 'web',
        public ?string $language = null,
    ) {}

    public static function fromRequest(StoreScratchpadPhotoRequest $request): self
    {
        /** @var UploadedFile $file */
        $file = $request->file('photo');

        return new self(
            file: $file,
            caption: $request->string('caption')->toString() ?: null,
            source: 'web',
            language: $request->string('language')->toString() ?: null,
        );
    }

    public static function fromTelegram(UploadedFile $file, ?string $caption): self
    {
        return new self(file: $file, caption: $caption, source: 'telegram');
    }

    public static function fromApi(UploadedFile $file, ?string $caption): self
    {
        return new self(file: $file, caption: $caption, source: 'api');
    }
}
