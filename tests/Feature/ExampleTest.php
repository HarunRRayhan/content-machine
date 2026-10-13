<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_a_successful_response()
    {
        $response = $this->get(route('home'));

        $response->assertOk();
    }

    public function test_logged_in_user_stays_on_landing_by_default()
    {
        $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()->assertSee('Dashboard')->assertDontSee('Log in');
    }

    public function test_logged_in_user_redirects_to_dashboard_when_enabled()
    {
        config(['app.redirect_home_to_dashboard' => true]);

        $this->actingAs(User::factory()->create())->get(route('home'))->assertRedirect(route('dashboard.home'));
        auth()->logout();
        $this->get(route('home'))->assertOk();
    }
}
