<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TranscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A transcription job/result for an audio or video MediaAsset. Voice-note
 * captures create a pending row and the scratchpad queue fills it through the
 * configured OpenAI-shaped transcription provider.
 *
 * @property int $id
 * @property int|null $scratchpad_entry_id
 * @property int $media_asset_id
 * @property string $status
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $language
 * @property string|null $text
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int|null $duration_ms
 * @property int|null $cost_cents
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Transcription extends Model
{
    /** @use HasFactory<TranscriptionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'scratchpad_entry_id',
        'media_asset_id',
        'status',
        'provider',
        'model',
        'language',
        'text',
        'error_code',
        'error_message',
        'duration_ms',
        'cost_cents',
    ];

    /**
     * The scratchpad entry this transcription belongs to, if it came from one.
     *
     * @return BelongsTo<ScratchpadEntry, $this>
     */
    public function scratchpadEntry(): BelongsTo
    {
        return $this->belongsTo(ScratchpadEntry::class);
    }

    /**
     * The audio/video file being transcribed.
     *
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
