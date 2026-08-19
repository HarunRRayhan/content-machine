<?php

namespace App\Actions\Users;

use App\Actions\Teams\CreateTeamAction;
use App\Data\Teams\CreateTeamData;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Creates a user with a personal team (mirrors what registration normally
 * does), for a self-hosted instance where DISABLE_REGISTRATION closes the
 * sign-up form and there's no other way to create the first account.
 * Idempotent: a second call with the same email is a no-op, so it's safe
 * to run on every deploy.
 */
class EnsureAdminUserAction
{
    public function __construct(private readonly CreateTeamAction $createTeamAction) {}

    /**
     * @return array{user: User, password: string}|null null if a user with
     *                                                  this email already exists
     */
    public function handle(string $email, string $name): ?array
    {
        if (User::where('email', $email)->exists()) {
            return null;
        }

        $password = Str::password(24);

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        $team = $this->createTeamAction->handle($user, CreateTeamData::fromOwner($user));
        $user->forceFill(['current_team_id' => $team->id])->save();

        return ['user' => $user, 'password' => $password];
    }
}
