<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramBotConfig;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TelegramBotConfigControllerTest extends TestCase
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

    public function test_guests_cannot_view_the_page()
    {
        $this->get(route('dashboard.telegram.edit'))->assertRedirect(route('login'));
    }

    public function test_a_workspace_with_no_config_shows_disconnected()
    {
        $this->actingAsWorkspaceMember();

        $this->get(route('dashboard.telegram.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('telegram/edit')
                ->where('connected', false)
                ->where('botUsername', null)
            );
    }

    public function test_a_connected_workspace_shows_its_bot_username()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        TelegramBotConfig::factory()->for($workspace)->connected()->create(['bot_username' => 'harun_capture_bot']);

        $this->get(route('dashboard.telegram.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connected', true)
                ->where('botUsername', 'harun_capture_bot')
            );
    }

    public function test_update_connects_with_a_valid_token()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['username' => 'harun_capture_bot'],
        ])]);

        $this->post(route('dashboard.telegram.update'), ['bot_token' => '123456:test-token'])
            ->assertRedirect(route('dashboard.telegram.edit'));

        $config = TelegramBotConfig::where('workspace_id', $workspace->id)->sole();
        $this->assertTrue($config->isConnected());
        $this->assertSame('harun_capture_bot', $config->bot_username);
        $this->assertSame('123456:test-token', $config->bot_token);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/setWebhook')
            && $request['secret_token'] === $config->webhook_secret
            && str_contains((string) $request['url'], $config->webhook_slug));
    }

    public function test_update_rejects_an_invalid_token_and_stores_nothing()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        $this->post(route('dashboard.telegram.update'), ['bot_token' => 'bad-token'])
            ->assertRedirect(route('dashboard.telegram.edit'));

        $this->assertSame(0, TelegramBotConfig::where('workspace_id', $workspace->id)->count());
    }

    public function test_destroy_disconnects_the_bot()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $config = TelegramBotConfig::factory()->for($workspace)->connected()->create();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->delete(route('dashboard.telegram.destroy'))
            ->assertRedirect(route('dashboard.telegram.edit'));

        $this->assertFalse($config->fresh()->isConnected());
    }
}
