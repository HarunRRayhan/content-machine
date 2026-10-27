<?php

namespace App\Data\Pexels;

use App\Http\Requests\Api\V1\SearchPexelsPhotosRequest;

final readonly class SearchPexelsPhotosData
{
    public function __construct(
        public string $query,
        public string $orientation,
        public int $perPage,
    ) {}

    public static function fromRequest(SearchPexelsPhotosRequest $request): self
    {
        return new self(
            $request->validated('query'),
            $request->validated('orientation', 'portrait'),
            $request->integer('per_page', 6),
        );
    }
}
