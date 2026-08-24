<?php

namespace Tests\Feature\Team;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsWorkspaceMember(): array
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        return [$user, $workspace];
    }

    public function test_the_team_page_lists_live_tokens_for_the_workspace()
    {
        [$user, $workspace] = $this->actingAsWorkspaceMember();

        WorkspaceApiToken::factory()->for($workspace, 'workspace')->create([
            'name' => 'personal-content',
            'created_by_user_id' => $user->id,
        ]);
        WorkspaceApiToken::factory()->create(['name' => 'someone else workspace']);

        $this->get(route('dashboard.team.index'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where('api_tokens.0.name', 'personal-content')
                    ->missing('api_tokens.1'),
            );
    }

    public function test_minting_a_token_shows_it_once_and_stores_only_a_hash()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $response = $this->post(route('dashboard.team.api-tokens.store'), [
            'name' => 'Script Studio',
            'abilities' => ['scratchpad:read', 'scratchpad:write'],
        ]);

        $response->assertRedirect(route('dashboard.team.index'));

        $token = WorkspaceApiToken::query()->sole();

        $this->assertSame('Script Studio', $token->name);
        $this->assertSame($workspace->id, $token->workspace_id);
        // Only the hash is stored: 64 hex chars, no cm_-prefixed plaintext
        // anywhere in the row.
        $this->assertSame(64, strlen($token->token_hash));
        $this->assertDoesNotMatchRegularExpression('/^cm_/', $token->token_hash);
    }

    public function test_minting_validates_abilities()
    {
        $this->actingAsWorkspaceMember();

        $this->from(route('dashboard.team.index'))
            ->post(route('dashboard.team.api-tokens.store'), [
                'name' => 'Bad',
                'abilities' => ['posts:publish'],
            ])
            ->assertSessionHasErrors('abilities.0');

        $this->assertSame(0, WorkspaceApiToken::query()->count());
    }

    public function test_revoking_stamps_revoked_at_and_kills_api_access()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $token = WorkspaceApiToken::factory()->create([
            'token_hash' => WorkspaceApiToken::hash('cm_revoke_me'),
            'created_by_user_id' => null,
        ]);
        // factory defaults to its own workspace; point it at ours.
        $token->forceFill(['workspace_id' => $workspace->id])->save();

        $this->delete(route('dashboard.team.api-tokens.revoke', ['apiToken' => $token->id]))
            ->assertRedirect(route('dashboard.team.index'));

        $this->assertNotNull($token->fresh()->revoked_at);

        $this->withToken('cm_revoke_me')->getJson('/api/v1/scratchpad')->assertUnauthorized();
    }

    public function test_a_token_from_another_workspace_cannot_be_revoked_here()
    {
        $this->actingAsWorkspaceMember();

        $foreign = WorkspaceApiToken::factory()->create();

        $this->delete(route('dashboard.team.api-tokens.revoke', ['apiToken' => $foreign->id]))
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->revoked_at);
    }
}
