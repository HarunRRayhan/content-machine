<?php

namespace App\Data\Media;

use App\Http\Requests\Media\StoreLibraryMediaRequest;
use App\Support\Media\MediaLibraryTab;
use Illuminate\Http\UploadedFile;

final readonly class UploadLibraryMediaData
{
    public function __construct(
        public MediaLibraryTab $tab,
        public UploadedFile $file,
        public ?string $title,
        public ?string $description,
    ) {}

    public static function fromRequest(StoreLibraryMediaRequest $request): self
    {
        return new self(
            tab: MediaLibraryTab::fromRoute($request->validated('tab')),
            file: $request->file('file'),
            title: $request->validated('title'),
            description: $request->validated('description'),
        );
    }
}
