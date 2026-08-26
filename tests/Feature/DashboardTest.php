<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard.home'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_visiting_the_dashboard_home_land_on_scratch_pad()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard.home'))
            ->assertRedirect(route('scratchpad.index'));
    }
}
