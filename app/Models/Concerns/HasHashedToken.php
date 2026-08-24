<?php

namespace App\Models\Concerns;

use App\Models\WorkspaceApiToken;

/**
 * Token hashing/matching helpers for WorkspaceApiToken. Kept as its own
 * concern so the hashing scheme has exactly one definition point; a future
 * PersonalAccessToken-style model would reuse it rather than copy the hash
 * call.
 */
trait HasHashedToken
{
    /**
     * The one-way transform between the plaintext a client holds and the
     * value stored in the database. SHA-256 of a high-entropy random string
     * (no human-chosen secrets here), so there is nothing to slow down.
     */
    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function findByPlaintext(string $plaintext): ?WorkspaceApiToken
    {
        /** @var WorkspaceApiToken|null */
        return static::query()
            ->where('token_hash', static::hash($plaintext))
            ->whereNull('revoked_at')
            ->first();
    }
}
