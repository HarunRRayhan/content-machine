<?php

namespace Tests\Unit\Data\Teams;

use App\Data\Teams\CreateTeamData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateTeamDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_from_owner_names_the_team_after_the_user()
    {
        $owner = User::factory()->make(['name' => 'Katherine Johnson']);

        $data = CreateTeamData::fromOwner($owner);

        $this->assertSame("Katherine Johnson's Team", $data->name);
    }
}
