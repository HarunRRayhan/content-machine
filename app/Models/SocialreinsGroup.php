<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One SocialReins post group created for a Post or Video: one brain
 * `POST /v1/post-groups` call for one language (set).
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $language
 * @property int $sequence
 * @property string $idempotency_key
 * @property string $state
 * @property string $request
 * @property string|null $group_id
 * @property array<string, array<string, mixed>>|null $results
 * @property string|null $error
 * @property CarbonImmutable|null $updated_at
 */
class SocialreinsGroup extends Model
{
    public const PENDING = 'pending';

    public const CREATED = 'created';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'workspace_id', 'subject_type', 'subject_id', 'language', 'sequence',
        'idempotency_key', 'state', 'request', 'group_id', 'results', 'error',
    ];

    protected function casts(): array
    {
        return ['results' => 'array'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Change the stored results under a row lock, so a webhook, a reconcile and a take down
     * that overlap each apply their change to the latest copy instead of overwriting it.
     * The callback gets the current results and returns the new ones (null leaves them).
     *
     * @param  \Closure(array<string, array<string, mixed>>): (array<string, array<string, mixed>>|null)  $change
     */
    public function mutateResults(\Closure $change): void
    {
        DB::transaction(function () use ($change): void {
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            $next = $change($locked->results ?? []);

            if ($next !== null) {
                $locked->forceFill(['results' => $next])->save();
            }
        });

        $this->refresh();
    }

    public function subject(): Post|Video|null
    {
        $class = $this->subject_type === 'video' ? Video::class : Post::class;

        return $class::query()->find($this->subject_id);
    }
}
