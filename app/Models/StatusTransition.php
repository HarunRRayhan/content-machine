<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only: this table is never updated or deleted, so the model has no
 * `updated_at` column and callers should never call ->update() on a row.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $from
 * @property string $to
 * @property string|null $reason
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $token_name
 * @property CarbonImmutable|null $created_at
 */
class StatusTransition extends Model
{
    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['from', 'to', 'reason', 'actor_type', 'actor_id', 'token_name'];

    /**
     * The subject this transition belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
