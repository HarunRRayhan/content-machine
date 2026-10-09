<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\RecordsHistory;
use Carbon\CarbonImmutable;
use Database\Factories\IdeaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A scored candidate (post/video/feature) waiting to be promoted into the
 * real pipeline, or dropped. Created by TriageScratchpadEntryAction (or
 * dropped directly via DropIdeaAction) and edited via UpdateIdeaAction, on
 * IdeasController's list/detail pages.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $kind
 * @property int $number
 * @property string $human_id
 * @property string $title
 * @property string $slug
 * @property int|null $score
 * @property string|null $trend
 * @property string|null $rationale
 * @property string|null $body
 * @property list<array{url: string, label: string|null}>|null $source_links
 * @property string|null $source_text
 * @property string|null $editorial_type
 * @property string $status
 * @property string|null $drop_reason
 * @property int|null $scratchpad_entry_id
 * @property int|null $after_idea_id
 * @property int|null $after_video_id
 * @property array<string, mixed> $details
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Idea extends Model
{
    /** @use HasFactory<IdeaFactory> */
    use BelongsToWorkspace, HasFactory, RecordsHistory;

    /** Allowed ideas.trend values. */
    public const TRENDS = ['evergreen', 'seasonal', 'short-trend'];

    /** Shelf life in days per trend; evergreen (and unset) never expires. */
    public const SHELF_LIFE_DAYS = ['short-trend' => 7, 'seasonal' => 60];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'kind',
        'number',
        'human_id',
        'title',
        'slug',
        'score',
        'trend',
        'rationale',
        'body',
        'source_links',
        'source_text',
        'editorial_type',
        'status',
        'drop_reason',
        'scratchpad_entry_id',
        'after_idea_id',
        'after_video_id',
        'details',
        'created_by_user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_links' => 'array',
            'details' => 'array',
        ];
    }

    /**
     * When this idea goes stale: created_at plus its trend's shelf life.
     * Null for evergreen or untagged ideas. Informational only, nothing
     * is dropped automatically.
     */
    public function expiresAt(): ?CarbonImmutable
    {
        $days = self::SHELF_LIFE_DAYS[$this->trend ?? ''] ?? null;

        return $days === null || $this->created_at === null
            ? null
            : $this->created_at->addDays($days);
    }

    /**
     * The scratchpad entry this idea was triaged from, if any.
     *
     * @return BelongsTo<ScratchpadEntry, $this>
     */
    public function scratchpadEntry(): BelongsTo
    {
        return $this->belongsTo(ScratchpadEntry::class);
    }

    /**
     * The idea that must be done before this one, if any.
     *
     * @return BelongsTo<Idea, $this>
     */
    public function afterIdea(): BelongsTo
    {
        return $this->belongsTo(self::class, 'after_idea_id');
    }

    /**
     * Ideas that name this one as their prerequisite.
     *
     * @return HasMany<Idea, $this>
     */
    public function dependents(): HasMany
    {
        return $this->hasMany(self::class, 'after_idea_id');
    }

    /**
     * The user who created this idea, if known.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The post this idea was promoted into, if it's a kind=post idea and
     * PromoteIdeaAction has run. Null for a video/feature idea or an
     * unpromoted one.
     *
     * @return HasOne<Post, $this>
     */
    public function post(): HasOne
    {
        return $this->hasOne(Post::class);
    }

    /**
     * The video this idea was promoted into, if it's a kind=video idea and
     * PromoteIdeaAction has run. Null for a post/feature idea or an
     * unpromoted one.
     *
     * @return HasOne<Video, $this>
     */
    public function video(): HasOne
    {
        return $this->hasOne(Video::class);
    }
}
