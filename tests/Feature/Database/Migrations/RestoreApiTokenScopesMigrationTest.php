<?php

namespace Tests\Feature\Database\Migrations;

use App\Models\WorkspaceApiToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestoreApiTokenScopesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_02_000007_restore_api_token_scopes.php');
    }

    public function test_it_removes_only_implicit_abilities_from_legacy_tokens(): void
    {
        $legacy = WorkspaceApiToken::factory()->create([
            'abilities' => WorkspaceApiToken::ABILITIES,
            'created_at' => '2026-08-20 00:00:00+00',
            'updated_at' => '2026-08-20 00:00:00+00',
        ]);
        $new = WorkspaceApiToken::factory()->create([
            'abilities' => WorkspaceApiToken::ABILITIES,
            'created_at' => '2026-09-01 00:00:00+00',
            'updated_at' => '2026-09-01 00:00:00+00',
        ]);

        $this->migration()->up();

        $this->assertSame([
            'scratchpad:read',
            'scratchpad:write',
            'ideas:read',
            'ideas:write',
        ], $legacy->fresh()->abilities);
        $this->assertSame(WorkspaceApiToken::ABILITIES, $new->fresh()->abilities);
    }
}
