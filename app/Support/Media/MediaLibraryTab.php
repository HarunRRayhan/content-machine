<?php

namespace App\Support\Media;

use Illuminate\Database\Eloquent\Builder;

enum MediaLibraryTab: string
{
    case Images = 'images';
    case Videos = 'videos';
    case Gifs = 'gifs';

    public function label(): string
    {
        return match ($this) {
            self::Images => 'Images',
            self::Videos => 'Videos',
            self::Gifs => 'GIFs',
        };
    }

    /**
     * @param  Builder<\App\Models\MediaAsset>  $query
     * @return Builder<\App\Models\MediaAsset>
     */
    public function applyTo(Builder $query): Builder
    {
        return match ($this) {
            self::Images => $query
                ->where('kind', 'image')
                ->where('mime', '!=', 'image/gif'),
            self::Gifs => $query
                ->where('kind', 'image')
                ->where('mime', 'image/gif'),
            self::Videos => $query->where('kind', 'video'),
        };
    }

    public static function fromRoute(string $tab): self
    {
        return self::from($tab);
    }
}
