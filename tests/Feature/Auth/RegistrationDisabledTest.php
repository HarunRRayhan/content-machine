<?php

namespace Tests\Feature\Auth;

use App\Models\TeamInvitation;
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

    public function test_a_valid_pending_invitation_opens_the_registration_screen()
    {
        $invitation = TeamInvitation::factory()->create();

        $this->get(route('invitations.show', $invitation->token));

        $this->get(route('register'))->assertOk();
    }

    public function test_registering_succeeds_with_a_valid_pending_invitation()
    {
        $invitation = TeamInvitation::factory()->create();

        $this->get(route('invitations.show', $invitation->token));

        $this->post(route('register.store'), [
            'name' => 'Invited Person',
            'email' => $invitation->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticated();
        $this->assertTrue($invitation->fresh()->isAccepted());
    }

    public function test_an_expired_invitation_does_not_reopen_registration()
    {
        $invitation = TeamInvitation::factory()->expired()->create();

        $this->get(route('invitations.show', $invitation->token));

        $this->get(route('register'))->assertForbidden();
    }

    public function test_an_already_accepted_invitation_does_not_reopen_registration()
    {
        $invitation = TeamInvitation::factory()->accepted()->create();

        $this->get(route('invitations.show', $invitation->token));

        $this->get(route('register'))->assertForbidden();
    }

    public function test_an_unrecognized_token_in_session_does_not_reopen_registration()
    {
        session(['pending_invitation_token' => 'not-a-real-token']);

        $this->get(route('register'))->assertForbidden();
    }
}
