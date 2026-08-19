<?php

namespace App\Data\Teams;

use App\Models\User;

/**
 * Typed input for CreateTeamAction. Built via ::fromOwner() rather than
 * ::fromRequest() since team creation is triggered by the Registered event,
 * not an HTTP request.
 */
final readonly class CreateTeamData
{
    public function __construct(
        public string $name,
    ) {}

    public static function fromOwner(User $owner): self
    {
        return new self(name: "{$owner->name}'s Team");
    }
}
