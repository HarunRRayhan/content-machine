<?php

namespace Tests\Unit\Actions\Pexels;

use App\Actions\Pexels\SavePexelsSettingsAction;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SavePexelsSettingsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_the_workspace_key_encrypted(): void
    {
        $workspace = Workspace::factory()->create();

        (new SavePexelsSettingsAction)->handle($workspace, ' workspace-secret ');

        $encrypted = $workspace->fresh()->settings['pexels']['api_key'];
        $this->assertNotSame('workspace-secret', $encrypted);
        $this->assertSame('workspace-secret', Crypt::decryptString($encrypted));
    }
}
