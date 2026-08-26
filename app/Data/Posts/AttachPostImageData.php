<?php

namespace App\Data\Posts;

use App\Http\Requests\Posts\StorePostImageRequest;
use Illuminate\Http\UploadedFile;

/**
 * Typed input for AttachPostImageAction.
 */
final readonly class AttachPostImageData
{
    public function __construct(
        public UploadedFile $file,
    ) {}

    public static function fromRequest(StorePostImageRequest $request): self
    {
        /** @var UploadedFile $file */
        $file = $request->file('image');

        return new self(file: $file);
    }

    public static function fromApi(UploadedFile $file): self
    {
        return new self(file: $file);
    }
}
