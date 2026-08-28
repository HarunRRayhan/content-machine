<?php

namespace App\Data\Media;

use App\Http\Requests\Media\UpdateMediaAssetRequest;

final readonly class UpdateMediaAssetData
{
    public function __construct(
        public ?string $title,
        public ?string $description,
    ) {}

    public static function fromRequest(UpdateMediaAssetRequest $request): self
    {
        return new self(
            title: $request->validated('title'),
            description: $request->validated('description'),
        );
    }
}
