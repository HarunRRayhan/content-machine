<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTokenAuthTest extends TestCase
{
    use RefreshDatabase;

    private function mintToken(Workspace $workspace, array $abilities = WorkspaceApiToken::ABILITIES): string
    {
        return (new CreateWorkspaceApiTokenAction)->handle(
            $workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('test client', $abilities),
        )['plaintext'];
    }

    public function test_a_request_without_a_token_is_unauthenticated()
    {
        $this->getJson('/api/v1/scratchpad')->assertUnauthorized();
    }

    public function test_an_unknown_token_is_unauthenticated()
    {
        Workspace::factory()->create();

        $this->withToken('cm_totally-made-up')->getJson('/api/v1/scratchpad')->assertUnauthorized();
    }

    public function test_a_non_cm_prefixed_token_is_rejected_before_lookup()
    {
        Workspace::factory()->create();

        $this->withToken('some-oauth-thing')->getJson('/api/v1/scratchpad')->assertUnauthorized();
    }

    public function test_a_revoked_token_is_unauthenticated()
    {
        $workspace = Workspace::factory()->create();
        $plaintext = $this->mintToken($workspace);

        WorkspaceApiToken::query()->firstOrFail()->forceFill(['revoked_at' => now()])->save();

        $this->withToken($plaintext)->getJson('/api/v1/scratchpad')->assertUnauthorized();
    }

    public function test_a_valid_token_authenticates_against_its_workspace()
    {
        $workspace = Workspace::factory()->create();
        $plaintext = $this->mintToken($workspace);

        $this->withToken($plaintext)->getJson('/api/v1/scratchpad')->assertOk();
    }

    public function test_a_token_without_the_required_ability_gets_forbidden()
    {
        $workspace = Workspace::factory()->create();
        $plaintext = $this->mintToken($workspace, ['scratchpad:read']);

        $this->withToken($plaintext)->postJson('/api/v1/scratchpad/text', [
            'body' => 'nope',
        ])->assertForbidden();
    }

    public function test_read_ability_grants_read_but_not_write()
    {
        $workspace = Workspace::factory()->create();
        $plaintext = $this->mintToken($workspace, ['scratchpad:read']);

        $this->withToken($plaintext)->getJson('/api/v1/scratchpad')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($plaintext)->deleteJson('/api/v1/scratchpad/01ANYTHING')->assertForbidden();
    }

    public function test_a_token_cannot_see_another_workspaces_entry()
    {
        $mine = Workspace::factory()->create();
        $theirs = Workspace::factory()->create();

        $foreignEntry = ScratchpadEntry::factory()->for($theirs)->create([
            'kind' => 'text',
            'body' => 'secret',
        ]);

        $this->withToken($this->mintToken($mine))
            ->getJson("/api/v1/scratchpad/{$foreignEntry->public_id}")
            ->assertNotFound();
    }

    public function test_media_url_checks_require_the_media_read_ability(): void
    {
        $workspace = Workspace::factory()->create();
        $plaintext = $this->mintToken($workspace, ['scratchpad:read']);

        $this->withToken($plaintext)
            ->postJson('/api/v1/media-urls/check', ['url' => 'https://drive.google.com/file/d/example/view'])
            ->assertForbidden();
    }
}
