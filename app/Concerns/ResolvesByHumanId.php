<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Lets a dashboard/show URL accept either the numeric primary key or the
 * public human_id (P-50, BV-46, BP-12, …). A value that starts with a
 * letter prefix and a hyphen is treated as the custom id; anything else
 * still binds as the regular route key.
 *
 * Used by Post and Video so /posts/P-50 and /videos/BV-46 resolve the
 * same way /posts/59 does.
 */
trait ResolvesByHumanId
{
    /**
     * A custom id looks like "P-50" or "BV-46": one or more letters, a
     * hyphen, then digits. Bare numbers stay numeric primary keys.
     */
    public static function looksLikeHumanId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z]+-\d+$/', $value) === 1;
    }

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     * @return Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field === null && static::looksLikeHumanId($value)) {
            $field = 'human_id';
        }

        return parent::resolveRouteBinding($value, $field);
    }
}
