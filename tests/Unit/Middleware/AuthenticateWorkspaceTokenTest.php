<?php

namespace Tests\Unit\Middleware;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Http\Middleware\AuthenticateWorkspaceToken;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentApiToken;
use App\Support\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthenticateWorkspaceTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_clears_token_and_workspace_context_after_a_request(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $created = (new CreateWorkspaceApiTokenAction)->handle(
            $workspace,
            $user,
            new CreateWorkspaceApiTokenData('test client', ['scratchpad:read']),
        );
        $plaintext = $created['plaintext'];
        $token = $created['token'];
        $request = Request::create('/api/v1/scratchpad', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plaintext,
        ]);
        $seen = [];
        $middleware = new AuthenticateWorkspaceToken(
            app(CurrentApiToken::class),
            app(CurrentWorkspace::class),
        );

        $middleware->handle($request, function (Request $request) use (&$seen, $token, $workspace, $user) {
            $requestUser = $request->user();
            $seen = [
                app(CurrentApiToken::class)->get()?->id === $token->id,
                app(CurrentWorkspace::class)->get()?->is($workspace),
                $requestUser instanceof User && $requestUser->is($user),
            ];

            return response('ok');
        }, 'scratchpad:read');

        $this->assertSame([true, true, true], $seen);
        $this->assertNull(app(CurrentApiToken::class)->get());
        $this->assertNull(app(CurrentWorkspace::class)->get());
        $this->assertNull(Auth::user());
    }
}
