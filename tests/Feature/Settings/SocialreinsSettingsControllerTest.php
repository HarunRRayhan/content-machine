<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SocialreinsSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SET = '11111111-1111-4111-8111-111111111111';

    private function memberOf(Workspace $workspace, string $role): void
    {
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => $role]);
        $this->actingAs($user);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'publish_driver' => 'socialreins',
            'base_url' => 'https://brain.socialreins.com/v1',
            'default_language' => 'english',
            'tiktok_privacy_level' => 'SELF_ONLY',
            'webhook_secret' => 'whsec_abc',
            'languages' => ['english' => ['set_id' => self::SET, 'token' => 'sr_set_live_secret']],
            ...$overrides,
        ];
    }

    public function test_admin_saves_driver_set_and_encrypted_secrets_and_the_page_never_returns_them(): void
    {
        $workspace = Workspace::factory()->create();
        $this->memberOf($workspace, 'owner');

        $this->put('/settings/socialreins', $this->payload())->assertRedirect(route('settings.socialreins.edit'));

        $workspace->refresh();
        $stored = $workspace->settings['socialreins'];
        $this->assertSame('socialreins', $workspace->settings['publish_driver']);
        $this->assertNotSame('sr_set_live_secret', $stored['languages']['english']['token']);
        $this->assertSame('sr_set_live_secret', Crypt::decryptString($stored['languages']['english']['token']));
        $this->assertSame('whsec_abc', SocialreinsConfig::fromWorkspace($workspace)->webhookSecret());

        $this->get(route('settings.socialreins.edit'))->assertOk()->assertInertia(fn ($page) => $page
            ->component('workspace-settings/socialreins')
            ->where('driver', 'socialreins')
            ->where('webhookSecretConfigured', true)
            ->where('languages.english.token_configured', true)
            ->where('webhookUrl', route('socialreins.webhook', ['workspace' => $workspace->id]))
            ->missing('languages.english.token')
            ->missing('webhookSecret'));
    }

    public function test_blank_secrets_keep_the_stored_ones_and_postsyncer_settings_are_untouched(): void
    {
        $workspace = Workspace::factory()->create(['settings' => ['postsyncer' => ['api_key' => 'enc', 'publish_enabled' => true]]]);
        $this->memberOf($workspace, 'admin');
        $this->put('/settings/socialreins', $this->payload())->assertRedirect();

        $this->put('/settings/socialreins', $this->payload([
            'webhook_secret' => '',
            'languages' => ['english' => ['set_id' => self::SET, 'token' => '']],
            'publish_driver' => 'postsyncer',
        ]))->assertRedirect();

        $workspace->refresh();
        $config = SocialreinsConfig::fromWorkspace($workspace);
        $this->assertSame('sr_set_live_secret', $config->token('english'));
        $this->assertSame('whsec_abc', $config->webhookSecret());
        $this->assertFalse(SocialreinsConfig::usesSocialreins($workspace));
        $this->assertSame(['api_key' => 'enc', 'publish_enabled' => true], $workspace->settings['postsyncer']);
    }

    public function test_tiktok_visibility_must_be_a_known_level_and_members_cannot_save(): void
    {
        $workspace = Workspace::factory()->create();
        $this->memberOf($workspace, 'admin');
        $this->put('/settings/socialreins', $this->payload(['tiktok_privacy_level' => 'EVERYONE']))
            ->assertSessionHasErrors('tiktok_privacy_level');

        $other = Workspace::factory()->create();
        $this->memberOf($other, 'member');
        $this->put('/settings/socialreins', $this->payload())->assertForbidden();
        $this->get(route('settings.socialreins.edit'))->assertForbidden();
    }

    public function test_a_workspace_is_not_ready_without_a_set_and_token_for_its_default_language(): void
    {
        $workspace = Workspace::factory()->create();
        SocialreinsConfig::write($workspace, ['languages' => ['english' => ['set_id' => self::SET]]]);

        $this->assertFalse(SocialreinsConfig::fromWorkspace($workspace->fresh())->isReadyForPublish());
    }
}
