<?php

namespace App\Concerns;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the current workspace (Workspace::current(), set by the
 * SetCurrentWorkspace middleware for the duration of a request).
 *
 * First real consumers: ScratchpadEntry and Idea. A model with a
 * workspace_id column that needs cross-workspace lookups (e.g. ContentId's
 * idempotency-key replay, MediaAsset) deliberately doesn't use this trait,
 * and instead exposes a plain `workspace()` BelongsTo. See
 * tests/Unit/Concerns/BelongsToWorkspaceTest.php for a dummy-model proof
 * that the scope filters correctly.
 *
 * A model using this trait must have a `workspace_id` column.
 */
trait BelongsToWorkspace
{
    /**
     * Boot the trait and add the global workspace scope.
     */
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder) {
            if ($workspace = Workspace::current()) {
                $builder->where($builder->getModel()->getTable().'.workspace_id', $workspace->id);
            }
        });

        static::creating(function ($model) {
            if ($model->workspace_id === null && $workspace = Workspace::current()) {
                $model->workspace_id = $workspace->id;
            }
        });
    }

    /**
     * The workspace this model belongs to.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
