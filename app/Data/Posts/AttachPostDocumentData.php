<?php

namespace App\Data\Posts;

use App\Http\Requests\Posts\StorePostDocumentRequest;
use Illuminate\Http\UploadedFile;

/**
 * Typed input for AttachPostDocumentAction.
 */
final readonly class AttachPostDocumentData
{
    public function __construct(
        public UploadedFile $file,
    ) {}

    public static function fromRequest(StorePostDocumentRequest $request): self
    {
        /** @var UploadedFile $file */
        $file = $request->file('document');

        return new self(file: $file);
    }

    public static function fromApi(UploadedFile $file): self
    {
        return new self(file: $file);
    }
}
