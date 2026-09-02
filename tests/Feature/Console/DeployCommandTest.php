<?php

namespace Tests\Feature\Console;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeployCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_migrates_and_ensures_the_admin_user_in_one_command()
    {
        config(['app.admin_email' => 'admin@example.com', 'app.admin_name' => 'Admin']);

        $this->artisan('cm:deploy')->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertDatabaseHas('users', ['email' => 'admin@example.com']);
    }

    public function test_it_is_a_no_op_for_the_admin_user_on_a_second_run()
    {
        config(['app.admin_email' => 'admin@example.com', 'app.admin_name' => 'Admin']);

        $this->artisan('cm:deploy')->assertExitCode(0);
        $this->artisan('cm:deploy')->assertExitCode(0);

        $this->assertSame(1, User::where('email', 'admin@example.com')->count());
    }

    public function test_worker_migration_readiness_check_passes_after_deploy(): void
    {
        config(['app.admin_email' => 'admin@example.com', 'app.admin_name' => 'Admin']);

        $this->artisan('cm:deploy')->assertExitCode(0);
        $this->artisan('cm:assert-migrations-ready')->assertExitCode(0);
    }

    public function test_it_backfills_known_post_templates(): void
    {
        config(['app.admin_email' => 'admin@example.com', 'app.admin_name' => 'Admin']);

        Post::factory()->create([
            'human_id' => 'P-45',
            'number' => 45,
            'template' => null,
        ]);

        $this->artisan('cm:deploy')->assertExitCode(0);

        $this->assertDatabaseHas('posts', [
            'human_id' => 'P-45',
            'template' => 'B',
        ]);
    }

    public function test_it_creates_the_scratchpad_uploads_directory_when_missing()
    {
        $root = storage_path('framework/testing/uploads-'.bin2hex(random_bytes(4)));
        $this->assertDirectoryDoesNotExist($root);

        config(['filesystems.disks.scratchpad.root' => $root]);

        $this->artisan('cm:deploy')->assertExitCode(0);

        $this->assertDirectoryExists($root);
        $this->assertTrue(is_writable($root));

        rmdir($root);
    }
}
