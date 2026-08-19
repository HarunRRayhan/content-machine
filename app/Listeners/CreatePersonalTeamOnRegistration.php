<?php

namespace App\Listeners;

use App\Actions\Teams\CreateTeamAction;
use App\Data\Teams\CreateTeamData;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

/**
 * Gives every newly registered user their own team and default workspace,
 * so the dashboard always has a tenancy context to render against.
 */
class CreatePersonalTeamOnRegistration
{
    public function __construct(private readonly CreateTeamAction $createTeamAction) {}

    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $team = $this->createTeamAction->handle($user, CreateTeamData::fromOwner($user));

        $user->forceFill(['current_team_id' => $team->id])->save();
    }
}
