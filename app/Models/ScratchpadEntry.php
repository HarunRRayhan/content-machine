<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\RecordsHistory;
use Carbon\CarbonImmutable;
use Database\Factories\ScratchpadEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * A raw capture from the Scratch Pad: a quick text note today, a voice
 * memo/photo/link/file once later phases add them. Nothing here is
 * triaged automatically; `status`/`intent` only change when a human routes
 * it via scratchpad-triage.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $public_id
 * @property string $kind
 * @property CarbonImmutable $captured_at
 * @property string $source
 * @property string|null $language
 * @property string|null $title
 * @property string|null $body
 * @property string $status
 * @property string|null $intent
 * @property CarbonImmutable|null $triaged_at
 * @property int|null $triaged_by_user_id
 * @property string|null $drop_reason
 * @property array<string, mixed> $meta
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ScratchpadEntry extends Model
{
    /** @use HasFactory<ScratchpadEntryFactory> */
    use BelongsToWorkspace, HasFactory, RecordsHistory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'public_id',
        'kind',
        'captured_at',
        'source',
        'language',
        'title',
        'body',
        'status',
        'intent',
        'triaged_at',
        'triaged_by_user_id',
        'drop_reason',
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
            'captured_at' => 'datetime',
            'triaged_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * Assign a public ulid on creation, same convention as MediaAsset.
     */
    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            $entry->public_id ??= (string) Str::ulid();
        });
    }

    /**
     * The user who triaged this entry, if it's been triaged.
     *
     * @return BelongsTo<User, $this>
     */
    public function triagedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triaged_by_user_id');
    }

    /**
     * Media files attached to this entry (photo capture, a voice memo's
     * audio, a resolved link's screenshot). Empty in this phase.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * This entry's transcription, if it's a voice capture. At most one row
     * in practice (CaptureScratchpadVoiceAction creates exactly one), kept
     * as hasMany rather than hasOne since Transcription's own schema
     * allows more.
     *
     * @return HasMany<Transcription, $this>
     */
    public function transcriptions(): HasMany
    {
        return $this->hasMany(Transcription::class);
    }

    /**
     * The idea this entry was triaged into, if any (via
     * TriageScratchpadEntryAction). At most one, since an entry can only be
     * triaged once.
     *
     * @return HasMany<Idea, $this>
     */
    public function ideas(): HasMany
    {
        return $this->hasMany(Idea::class);
    }
}
