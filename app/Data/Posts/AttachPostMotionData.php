<?php

namespace App\Data\Posts;

use Illuminate\Http\UploadedFile;

/**
 * Typed input for AttachPostMotionAction.
 */
final readonly class AttachPostMotionData
{
    public function __construct(
        public UploadedFile $file,
        public string $filename,
        public string $platform,
        public string $language,
        public ?int $order,
    ) {}
}
