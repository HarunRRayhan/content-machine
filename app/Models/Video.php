<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\RecordsHistory;
use Carbon\CarbonImmutable;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A draft video shell, created by PromoteIdeaAction from an open, kind=video
 * Idea. Same shape and same "snapshot, not a live reference" copy-in as
 * Post (see that model). Scripts, decks, and scheduling are a later
 * phase's own tables/columns, not built here.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $idea_id
 * @property int $number
 * @property string $human_id
 * @property string $title
 * @property string|null $body
 * @property string $status
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use BelongsToWorkspace, HasFactory, RecordsHistory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'idea_id',
        'number',
        'human_id',
        'title',
        'body',
        'status',
        'created_by_user_id',
    ];

    /**
     * The idea this video was promoted from, if any.
     *
     * @return BelongsTo<Idea, $this>
     */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    /**
     * The user who promoted this video into existence, if known.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
