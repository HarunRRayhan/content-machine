<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoSeries extends Model
{
    use BelongsToWorkspace;

    protected $table = 'video_series';

    protected $fillable = ['workspace_id', 'slug', 'title'];

    /** @return HasMany<Video, $this> */
    public function videos(): HasMany
    {
        return $this->hasMany(Video::class, 'series_id')->orderBy('series_part');
    }
}
