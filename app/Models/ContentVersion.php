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
 * @property string $versionable_type
 * @property int $versionable_id
 * @property string $field
 * @property string|null $old_value
 * @property string|null $new_value
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $token_name
 * @property CarbonImmutable|null $created_at
 */
class ContentVersion extends Model
{
    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['field', 'old_value', 'new_value', 'actor_type', 'actor_id', 'token_name'];

    /**
     * The model this version belongs to.
     *
     * @return MorphTo<Model, $this>
     */
    public function versionable(): MorphTo
    {
        return $this->morphTo();
    }
}
