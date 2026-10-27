<?php

namespace App\Contracts;

use App\Data\Pexels\PexelsPhotoData;

interface PexelsProvider
{
    /** @return list<PexelsPhotoData> */
    public function search(string $query, string $orientation, int $perPage): array;

    public function photo(int $id): PexelsPhotoData;

    /** @return array{bytes: string, mime: string} */
    public function download(PexelsPhotoData $photo): array;
}
