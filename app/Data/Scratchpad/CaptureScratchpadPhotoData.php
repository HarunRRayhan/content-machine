<?php

namespace App\Data\Scratchpad;

use App\Http\Requests\Scratchpad\StoreScratchpadPhotoRequest;
use Illuminate\Http\UploadedFile;

/**
 * Typed input for CaptureScratchpadPhotoAction.
 */
final readonly class CaptureScratchpadPhotoData
{
    public function __construct(
        public UploadedFile $file,
        public ?string $caption,
    ) {}

    public static function fromRequest(StoreScratchpadPhotoRequest $request): self
    {
        /** @var UploadedFile $file */
        $file = $request->file('photo');

        return new self(
            file: $file,
            caption: $request->string('caption')->toString() ?: null,
        );
    }
}
