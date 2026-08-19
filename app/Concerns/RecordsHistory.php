<?php

namespace App\Concerns;

use App\Models\ContentVersion;
use App\Models\StatusTransition;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

/**
 * Gives any Eloquent model append-only status/field history, backed by the
 * `status_transitions` and `content_versions` tables. Nothing populates
 * these yet, since no content model (ideas, posts, videos) exists in this
 * phase; the trait exists so those later models can `use RecordsHistory`
 * from day one instead of retrofitting audit logging later. See
 * tests/Unit/Concerns/RecordsHistoryTest.php for proof against a dummy
 * model.
 */
trait RecordsHistory
{
    /**
     * @return MorphMany<StatusTransition, $this>
     */
    public function statusTransitions(): MorphMany
    {
        return $this->morphMany(StatusTransition::class, 'subject');
    }

    /**
     * @return MorphMany<ContentVersion, $this>
     */
    public function contentVersions(): MorphMany
    {
        return $this->morphMany(ContentVersion::class, 'versionable');
    }

    public function recordStatusTransition(?string $from, string $to, ?string $reason = null): StatusTransition
    {
        [$actorType, $actorId, $tokenName] = $this->resolveActor();

        return $this->statusTransitions()->create([
            'from' => $from,
            'to' => $to,
            'reason' => $reason,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'token_name' => $tokenName,
        ]);
    }

    public function recordFieldChange(string $field, mixed $old, mixed $new): ContentVersion
    {
        [$actorType, $actorId, $tokenName] = $this->resolveActor();

        return $this->contentVersions()->create([
            'field' => $field,
            'old_value' => $this->stringifyHistoryValue($old),
            'new_value' => $this->stringifyHistoryValue($new),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'token_name' => $tokenName,
        ]);
    }

    /**
     * Resolve who is performing the action being recorded.
     *
     * Defaults to the authenticated user, falling back to 'system'. There's
     * no API/token-driven actor in this phase, so the 'token' case is left
     * for a later phase to add by overriding this method.
     *
     * @return array{0: string, 1: int|null, 2: string|null} [actor_type, actor_id, token_name]
     */
    protected function resolveActor(): array
    {
        if ($user = Auth::user()) {
            return ['user', $user->id, null];
        }

        return ['system', null, null];
    }

    private function stringifyHistoryValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value);

        return $encoded === false ? null : $encoded;
    }
}
