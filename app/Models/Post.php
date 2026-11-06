<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use App\Concerns\RecordsHistory;
use App\Concerns\ResolvesByHumanId;
use Carbon\CarbonImmutable;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string $category
 * @property string|null $body
 * @property array<string, mixed>|null $captions
 * @property array<string, mixed>|null $platforms
 * @property array<int, string>|null $image_drive_urls
 * @property array<string, mixed>|null $postsyncer
 * @property string $publish_state
 * @property string|null $publish_error
 * @property array<string, mixed>|null $publish_progress
 * @property CarbonImmutable|null $publish_claimed_at
 * @property string|null $publish_lease_id
 * @property string $approval_state
 * @property CarbonImmutable|null $approved_at
 * @property int|null $approved_by_user_id
 * @property string $status
 * @property string|null $blocked_reason
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
        'blocked',
        'archived',
    ];

    public const PUBLISH_STATES = [
        'idle',
        'queued',
        'running',
        'succeeded',
        'failed',
    ];

    /** Calendar color group; metadata only, never resets approval. */
    public const CATEGORIES = [
        'default',
        'morning',
        'evening_carousel',
    ];

    public const APPROVAL_STATES = [
        'pending',
        'approved',
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
        'category',
        'body',
        'captions',
        'platforms',
        'image_drive_urls',
        'postsyncer',
        'publish_progress',
        'publish_claimed_at',
        'publish_lease_id',
        'publish_state',
        'publish_error',
        'approval_state',
        'approved_at',
        'approved_by_user_id',
        'status',
        'blocked_reason',
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
            'publish_claimed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * "P-290", "p-290" and bare "290" all address the same post: a bare
     * number is the P- label's number. Other legacy prefixes (BP-7) are only
     * upper-cased. The P- prefix is a display label, never required.
     */
    public static function normalizeLabel(string $value): string
    {
        $value = trim($value);

        return ctype_digit($value) ? "P-{$value}" : strtoupper($value);
    }

    /**
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public function scopeWhereLabel(Builder $query, string $value): Builder
    {
        return $query->where('human_id', static::normalizeLabel($value));
    }

    /**
     * A bare number never binds to the primary key: old posts have id !=
     * number, new posts have id == number, so label lookup is unambiguous.
     * An explicit field (e.g. {post:id} on the signed media route) still wins.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field === null) {
            return $this->newQuery()->whereLabel((string) $value)->first();
        }

        return parent::resolveRouteBinding($value, $field);
    }

    /** Bare-number URLs for P- posts (/posts/290); legacy prefixes keep theirs. */
    public function getRouteKey(): string
    {
        return $this->human_id === "P-{$this->number}" ? (string) $this->number : (string) $this->human_id;
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

        if (! is_array($progress) || ($progress['state'] ?? null) !== 'failed') {
            return false;
        }

        $current = $progress['current'] ?? null;

        return $current === null
            || (is_array($current) && ($current['phase'] ?? null) === 'retryable');
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

    /**
     * @return HasMany<TelegramPostRequest, $this>
     */
    public function telegramPostRequests(): HasMany
    {
        return $this->hasMany(TelegramPostRequest::class);
    }

    /**
     * A media mutation changes the reviewed publish payload just like a text
     * mutation. Re-open any approval that was granted for the old payload.
     */
    public function invalidateApproval(): void
    {
        if ($this->approval_state !== 'approved') {
            return;
        }

        $this->forceFill([
            'approval_state' => 'pending',
            'approved_at' => null,
            'approved_by_user_id' => null,
        ])->save();

        $this->telegramPostRequests()
            ->whereIn('state', [
                TelegramPostRequest::APPROVED,
                TelegramPostRequest::FAILED,
            ])
            ->update([
                'state' => TelegramPostRequest::AWAITING_APPROVAL,
                'confirmed_at' => null,
                'error_message' => null,
            ]);
    }
}
