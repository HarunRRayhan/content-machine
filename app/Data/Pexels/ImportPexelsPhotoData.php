<?php

namespace App\Data\Pexels;

use App\Http\Requests\Api\V1\ImportPexelsPhotoRequest;

final readonly class ImportPexelsPhotoData
{
    public function __construct(public int $id) {}

    public static function fromRequest(ImportPexelsPhotoRequest $request): self
    {
        return new self($request->integer('id'));
    }
}
