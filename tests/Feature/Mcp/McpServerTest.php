<?php

namespace Tests\Feature\Mcp;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->token = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('mcp client'),
        )['plaintext'];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function mcp(array $body)
    {
        return $this->withToken($this->token)->postJson('/mcp', $body);
    }

    public function test_unauthenticated_mcp_calls_are_rejected(): void
    {
        $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
        ])->assertUnauthorized();
    }

    public function test_preflight_does_not_need_a_token(): void
    {
        $this->options('/mcp')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_initialize_returns_server_info_and_cors(): void
    {
        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1'],
            ],
        ])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertJsonPath('result.serverInfo.name', 'content-machine')
            ->assertJsonPath('result.protocolVersion', '2025-03-26');
    }

    public function test_tools_list_includes_scratchpad_and_ideas(): void
    {
        $names = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ])
            ->assertOk()
            ->json('result.tools');

        $this->assertIsArray($names);
        $this->assertContains('list_scratchpad', array_column($names, 'name'));
        $this->assertContains('update_idea', array_column($names, 'name'));
    }

    public function test_capture_note_and_list_scratchpad_round_trip(): void
    {
        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'capture_note',
                'arguments' => ['body' => 'MCP captured this'],
            ],
        ])->assertOk()->assertJsonMissingPath('result.isError');

        $entry = ScratchpadEntry::query()->sole();
        $this->assertSame('MCP captured this', $entry->body);
        $this->assertSame('api', $entry->source);

        $listed = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'list_scratchpad',
                'arguments' => ['status' => 'new'],
            ],
        ])->assertOk()->json('result.content.0.text');

        $this->assertIsString($listed);
        $this->assertStringContainsString($entry->public_id, $listed);
        $this->assertStringContainsString('MCP captured this', $listed);
    }

    public function test_missing_ability_is_a_tool_error_not_a_hard_401(): void
    {
        $readOnly = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('read only', ['scratchpad:read', 'ideas:read']),
        )['plaintext'];

        $this->withToken($readOnly)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'capture_note',
                'arguments' => ['body' => 'nope'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'Token is missing the [scratchpad:write] ability.');

        $this->assertSame(0, ScratchpadEntry::query()->count());
    }

    public function test_notification_returns_202(): void
    {
        $this->mcp([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ])->assertAccepted();
    }

    public function test_api_access_page_exposes_the_mcp_url(): void
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->get(route('dashboard.team.api-tokens.index'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('dashboard/api-tokens')
                    ->where('mcp_url', url('/mcp')),
            );
    }
}
