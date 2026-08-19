<?php

namespace App\Data\Teams;

use App\Http\Requests\Team\InviteTeamMemberRequest;

/**
 * Typed input for InviteTeamMemberAction.
 */
final readonly class InviteTeamMemberData
{
    public function __construct(
        public string $email,
        public string $role,
    ) {}

    public static function fromRequest(InviteTeamMemberRequest $request): self
    {
        return new self(
            email: $request->string('email')->toString(),
            role: $request->string('role')->toString(),
        );
    }
}
