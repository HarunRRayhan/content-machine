<?php

namespace App\Actions\Pexels;

use App\Contracts\PexelsProvider;
use App\Data\Pexels\PexelsPhotoData;
use App\Data\Pexels\SearchPexelsPhotosData;

class SearchPexelsPhotosAction
{
    /** @return list<PexelsPhotoData> */
    public function handle(SearchPexelsPhotosData $data, PexelsProvider $provider): array
    {
        return $provider->search($data->query, $data->orientation, $data->perPage);
    }
}
