<?php

namespace App\Support\Socialreins;

use RuntimeException;

class SocialreinsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        /** @var array<mixed> brain's JSON answer, when it gave one (a 409 outcome_unknown lists its targets) */
        public readonly array $body = [],
    ) {
        parent::__construct($message, $status ?? 0);
    }

    /**
     * Brain answered and refused: a stored 4xx, so nothing was created and the same
     * Idempotency-Key would only replay the refusal. 408, 429, 409 `idempotency_in_progress`
     * and 422 `idempotency_key_reused` are not final; neither is a 5xx or a lost connection.
     */
    public function isDefinitive(): bool
    {
        if ($this->status === null || $this->status < 400 || $this->status >= 500) {
            return false;
        }

        // idempotency_key_reused means the key was used for a different body: a new key would
        // post again what the first one may already have created, so it never closes a row.
        return ! in_array($this->status, [408, 429], true)
            && ! in_array($this->errorCode, ['idempotency_in_progress', 'idempotency_key_reused'], true);
    }
}
