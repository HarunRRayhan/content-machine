<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureAdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_skips_when_admin_email_is_not_configured()
    {
        config(['app.admin_email' => null]);

        $this->artisan('cm:ensure-admin')->assertExitCode(0);

        $this->assertSame(0, User::count());
    }

    public function test_it_creates_the_admin_user_and_prints_the_password_once()
    {
        config(['app.admin_email' => 'admin@example.com', 'app.admin_name' => 'Admin']);

        $this->artisan('cm:ensure-admin')
            ->expectsOutputToContain('Created admin user admin@example.com.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com']);
    }

    public function test_it_is_a_no_op_on_a_second_run()
    {
        config(['app.admin_email' => 'admin@example.com', 'app.admin_name' => 'Admin']);

        $this->artisan('cm:ensure-admin')->assertExitCode(0);
        $this->artisan('cm:ensure-admin')
            ->expectsOutputToContain('already exists, skipping.')
            ->assertExitCode(0);

        $this->assertSame(1, User::where('email', 'admin@example.com')->count());
    }
}
