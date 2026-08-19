<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());

        config(['app.registration_enabled' => false]);
    }

    public function test_the_registration_screen_is_blocked()
    {
        $this->get(route('register'))->assertForbidden();
    }

    public function test_registering_is_blocked()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertForbidden();

        $this->assertGuest();
    }

    public function test_the_login_page_hides_the_sign_up_link()
    {
        $this->get(route('login'))->assertInertia(
            fn ($page) => $page->where('registrationEnabled', false)
        );
    }
}
