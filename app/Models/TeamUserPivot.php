<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The `team_user` pivot, typed so relations that eager-load it
 * (Team::members(), User::teams()) expose a real `role` property instead of
 * an untyped magic attribute.
 *
 * @property int $team_id
 * @property int $user_id
 * @property string $role
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class TeamUserPivot extends Pivot
{
    protected $table = 'team_user';
}
