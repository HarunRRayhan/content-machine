<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A stored file (image/video/audio/document) and its metadata. Schema-only
 * in this phase: nothing populates this table yet, since no capture flow
 * uploads a file (that's photo/voice capture, a later slice).
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $public_id
 * @property string $kind
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $bytes
 * @property string|null $checksum_sha256
 * @property int|null $width
 * @property int|null $height
 * @property int|null $duration_ms
 * @property string|null $original_filename
 * @property int|null $uploaded_by_user_id
 * @property array<string, mixed> $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'public_id',
        'kind',
        'disk',
        'path',
        'mime',
        'bytes',
        'checksum_sha256',
        'width',
        'height',
        'duration_ms',
        'original_filename',
        'uploaded_by_user_id',
        'meta',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $asset) {
            $asset->public_id ??= (string) Str::ulid();
        });
    }

    /**
     * The workspace this asset belongs to.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The user who uploaded it, if known.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Every attachment (scratchpad entry, post, video, ...) this asset is used in.
     *
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * This asset's transcription, if it's audio/video that's been transcribed.
     *
     * @return HasMany<Transcription, $this>
     */
    public function transcriptions(): HasMany
    {
        return $this->hasMany(Transcription::class);
    }
}
