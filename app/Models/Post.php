<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\RecordsHistory;
use App\Concerns\ResolvesByHumanId;
use Carbon\CarbonImmutable;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A post in the content pipeline. Started as a draft shell from
 * PromoteIdeaAction; now also holds captions, platforms, and language so
 * personal-content can read/write posts entirely over the API.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $idea_id
 * @property int $number
 * @property string $human_id
 * @property string $title
 * @property string|null $language
 * @property string|null $slug
 * @property string|null $template
 * @property string|null $body
 * @property array<string, mixed>|null $captions
 * @property array<string, mixed>|null $platforms
 * @property array<int, string>|null $image_drive_urls
 * @property array<string, mixed>|null $postsyncer
 * @property array<string, mixed>|null $publish_progress
 * @property string $publish_state
 * @property string|null $publish_error
 * @property string $status
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use BelongsToWorkspace, HasFactory, RecordsHistory, ResolvesByHumanId;

    public const STATUSES = [
        'draft',
        'ready',
        'scheduled',
        'posted',
        'archived',
        'dropped',
    ];

    public const PUBLISH_STATES = [
        'idle',
        'queued',
        'running',
        'succeeded',
        'failed',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'idea_id',
        'number',
        'human_id',
        'title',
        'language',
        'slug',
        'template',
        'body',
        'captions',
        'platforms',
        'image_drive_urls',
        'postsyncer',
        'publish_progress',
        'publish_state',
        'publish_error',
        'status',
        'created_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'captions' => 'array',
            'platforms' => 'array',
            'image_drive_urls' => 'array',
            'postsyncer' => 'array',
            'publish_progress' => 'array',
        ];
    }

    public function isPublishInProgress(): bool
    {
        return in_array($this->publish_state, ['queued', 'running'], true);
    }

    public function hasUncertainPublish(): bool
    {
        $progress = $this->publish_progress;

        return is_array($progress)
            && (($progress['state'] ?? null) === 'uncertain'
                || ($progress['current'] ?? null) !== null);
    }

    public function canRetryPublish(): bool
    {
        if ($this->publish_state !== 'failed') {
            return false;
        }

        $groups = $this->postsyncer['groups'] ?? null;
        if (is_array($groups)) {
            foreach ($groups as $group) {
                if (is_array($group) && filled($group['post_id'] ?? null)) {
                    return false;
                }
            }
        }

        $progress = $this->publish_progress;

        return is_array($progress)
            && ($progress['state'] ?? null) === 'failed'
            && ($progress['current'] ?? null) === null;
    }

    /**
     * @return BelongsTo<Idea, $this>
     */
    public function idea(): BelongsTo
    {
        return $this->belongsTo(Idea::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('position');
    }
}
