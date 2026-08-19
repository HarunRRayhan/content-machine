<?php

namespace App\Actions\Teams;

use App\Data\Teams\CreateTeamData;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a team for an owner, with its default workspace and the owner's
 * `team_user` membership row, in a single transaction.
 *
 * Deliberately doesn't touch $owner->current_team_id — whether a freshly
 * created team becomes the user's active one is a decision for the caller
 * (CreatePersonalTeamOnRegistration always wants it, a hypothetical future
 * "create another team" flow might not).
 */
class CreateTeamAction
{
    public function handle(User $owner, CreateTeamData $data): Team
    {
        return DB::transaction(function () use ($owner, $data) {
            $team = Team::create([
                'name' => $data->name,
                'slug' => $this->uniqueSlug($data->name),
                'owner_id' => $owner->id,
            ]);

            $team->members()->attach($owner->id, ['role' => 'owner']);

            $team->workspaces()->create([
                'name' => 'Default',
                'slug' => 'default',
            ]);

            return $team;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'team';
        $slug = $base;
        $suffix = 1;

        while (Team::where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
