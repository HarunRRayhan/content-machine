<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exits_successfully_against_the_test_database()
    {
        $this->artisan('cm:doctor')->assertExitCode(0);
    }
}
