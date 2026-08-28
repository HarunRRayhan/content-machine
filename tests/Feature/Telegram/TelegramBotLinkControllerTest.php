<?php

namespace Tests\Feature\Telegram;

use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramLinkCode;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramBotLinkControllerTest extends TestCase
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

    public function test_store_generates_a_link_code_flashed_back_to_the_edit_page()
    {
        [$user, $workspace] = $this->actingAsWorkspaceMember();
        TelegramBotConfig::factory()->for($workspace)->connected()->create();

        $this->post(route('settings.telegram.link-code'))
            ->assertRedirect(route('settings.telegram.edit'));

        $code = TelegramLinkCode::where('user_id', $user->id)->sole();
        $this->assertTrue($code->isUsable());
    }

    public function test_store_404s_when_the_bot_is_not_connected()
    {
        $this->actingAsWorkspaceMember();

        $this->post(route('settings.telegram.link-code'))->assertNotFound();
    }

    public function test_test_sends_a_message_to_the_users_linked_chat()
    {
        [$user, $workspace] = $this->actingAsWorkspaceMember();
        $config = TelegramBotConfig::factory()->for($workspace)->connected()->create();
        TelegramBotLink::factory()->create(['telegram_bot_config_id' => $config->id, 'user_id' => $user->id, 'telegram_user_id' => 42]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->post(route('settings.telegram.test'))
            ->assertRedirect(route('settings.telegram.edit'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage') && (int) $request['chat_id'] === 42);
    }

    public function test_test_fails_honestly_when_the_user_has_not_linked_yet()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        TelegramBotConfig::factory()->for($workspace)->connected()->create();

        $this->post(route('settings.telegram.test'))
            ->assertRedirect(route('settings.telegram.edit'));
    }
}
