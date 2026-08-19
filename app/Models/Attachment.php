<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Links a MediaAsset to whatever it's attached to (a scratchpad entry
 * today; a post/video later), with a role and position for ordering.
 * Schema-only in this phase: nothing populates this table yet.
 *
 * @property int $id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property int $media_asset_id
 * @property string $role
 * @property string|null $platform
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'media_asset_id',
        'role',
        'platform',
        'position',
    ];

    /**
     * The model this attachment is attached to.
     *
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The underlying media file.
     *
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
