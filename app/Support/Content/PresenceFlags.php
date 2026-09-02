<?php

namespace App\Support\Content;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Presence flags for list/summary queries so callers can avoid selecting
 * heavy JSONB / text columns (deck_manifest, captions, script_markdown).
 */
final class PresenceFlags
{
    /**
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $columns
     * @return Builder<covariant Model>
     */
    public static function selectVideoSummary(Builder $query, array $columns): Builder
    {
        return $query
            ->select($columns)
            ->selectRaw("(script_markdown IS NOT NULL AND BTRIM(script_markdown) <> '') AS has_script")
            ->selectRaw("(captions IS NOT NULL AND captions::text NOT IN ('null', '{}', '[]')) AS has_captions")
            ->selectRaw("(deck_manifest IS NOT NULL
                AND jsonb_typeof(deck_manifest->'js') = 'string'
                AND BTRIM(deck_manifest->>'js') <> ''
                AND jsonb_typeof(deck_manifest->'deck_key') = 'string'
                AND BTRIM(deck_manifest->>'deck_key') <> ''
                AND ((deck_manifest->>'js') LIKE ('%' || (deck_manifest->>'deck_key') || '%')
                    OR (deck_manifest->>'js') LIKE ('%' || regexp_replace(deck_manifest->>'deck_key', '^(bv|ev)-', 'v-', 'i') || '%'))
                AND ((deck_manifest->>'js') ~ 'stage[[:space:]]*:[[:space:]]*function'
                    OR (deck_manifest->>'js') ~ 'slidesHtml[[:space:]]*:[[:space:]]*function')
                AND (deck_manifest->>'js') ~ 'steps[[:space:]]*:'
                AND (deck_manifest->>'js') ~ '(cue|note)[[:space:]]*:') AS has_deck");
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $columns
     * @return Builder<covariant Model>
     */
    public static function selectPostSummary(Builder $query, array $columns): Builder
    {
        return $query
            ->select($columns)
            ->selectRaw("(body IS NOT NULL AND BTRIM(body) <> '') AS has_body")
            ->selectRaw("(captions IS NOT NULL AND captions::text NOT IN ('null', '{}', '[]')) AS has_captions");
    }

    public static function bool(Model $model, string $attribute, callable $fallback): bool
    {
        if (! array_key_exists($attribute, $model->getAttributes())) {
            return (bool) $fallback();
        }

        $value = $model->getAttributes()[$attribute];

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 't', 'true', 'yes'], true);
        }

        return (bool) $value;
    }
}
