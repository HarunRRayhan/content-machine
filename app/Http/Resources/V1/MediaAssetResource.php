<?php

namespace App\Http\Resources\V1;

use App\Models\MediaAsset;
use App\Support\Media\PresentMediaAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MediaAsset
 */
class MediaAssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return (new PresentMediaAsset)->summary($this->resource);
    }
}
