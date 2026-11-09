<?php

namespace Tests\Feature\Settings;

use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\LinkResolution\PublicUrlGuard;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesPublicDns;
use Tests\TestCase;

class SocialreinsSettingsControllerTest extends TestCase
{
    use FakesPublicDns, RefreshDatabase;

    private const SET = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolveHostsTo();
    }

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

    public function test_routed_languages_are_saved_cleared_and_validated(): void
    {
        $workspace = Workspace::factory()->create();
        $this->memberOf($workspace, 'owner');

        // The form always sends an empty hidden entry so that unticking everything clears the list.
        $this->put('/settings/socialreins', $this->payload(['routed_languages' => ['', 'bangla']]))->assertRedirect();
        $this->assertSame(['bangla'], SocialreinsConfig::fromWorkspace($workspace->fresh())->routedLanguages());
        $this->get(route('settings.socialreins.edit'))
            ->assertInertia(fn ($page) => $page->where('routedLanguages', ['bangla']));

        $this->put('/settings/socialreins', $this->payload(['routed_languages' => ['']]))->assertRedirect();
        $this->assertSame([], SocialreinsConfig::fromWorkspace($workspace->fresh())->routedLanguages());

        $this->put('/settings/socialreins', $this->payload(['routed_languages' => ['french']]))
            ->assertSessionHasErrors('routed_languages.0');
    }

    public function test_a_save_without_routed_languages_keeps_them(): void
    {
        $workspace = Workspace::factory()->create();
        $this->memberOf($workspace, 'owner');
        $this->put('/settings/socialreins', $this->payload(['routed_languages' => ['bangla']]))->assertRedirect();

        $this->put('/settings/socialreins', $this->payload())->assertRedirect();

        $this->assertSame(['bangla'], SocialreinsConfig::fromWorkspace($workspace->fresh())->routedLanguages());
    }

    public function test_routing_cannot_change_while_a_publish_is_in_flight_but_other_settings_can(): void
    {
        $workspace = Workspace::factory()->create();
        $this->memberOf($workspace, 'owner');
        $this->put('/settings/socialreins', $this->payload())->assertRedirect();
        Post::factory()->for($workspace)->create(['publish_state' => 'queued']);

        $this->put('/settings/socialreins', $this->payload(['routed_languages' => ['bangla']]))
            ->assertSessionHasErrors('publish_driver');
        $this->assertSame([], SocialreinsConfig::fromWorkspace($workspace->fresh())->routedLanguages());

        $this->put('/settings/socialreins', $this->payload(['publish_driver' => 'postsyncer']))
            ->assertSessionHasErrors('publish_driver');
        $this->assertTrue(SocialreinsConfig::usesSocialreins($workspace->fresh()));

        $this->put('/settings/socialreins', $this->payload(['tiktok_privacy_level' => 'PUBLIC_TO_EVERYONE']))
            ->assertSessionHasNoErrors();
        $this->assertSame('PUBLIC_TO_EVERYONE', SocialreinsConfig::fromWorkspace($workspace->fresh())->tiktokPrivacy());
    }

    /** @return array<string, array{string}> */
    public static function unsafeBaseUrls(): array
    {
        return [
            'http scheme' => ['http://brain.socialreins.com/v1'],
            'userinfo' => ['https://user:pass@brain.socialreins.com/v1'],
            'query' => ['https://brain.socialreins.com/v1?x=1'],
            'fragment' => ['https://brain.socialreins.com/v1#x'],
            'private ip' => ['https://10.0.0.5/v1'],
            'loopback ip' => ['https://127.0.0.1/v1'],
            'localhost' => ['https://localhost/v1'],
            'metadata ip' => ['https://169.254.169.254/v1'],
            'not a url' => ['brain'],
        ];
    }

    #[DataProvider('unsafeBaseUrls')]
    public function test_an_unsafe_base_url_is_refused(string $url): void
    {
        $workspace = Workspace::factory()->create();
        $this->memberOf($workspace, 'owner');
        // Real-looking names still resolve to something public here; literal IPs and
        // localhost are caught by the guard itself.
        $this->app->instance(PublicUrlGuard::class, new PublicUrlGuard(
            fn (string $host): array => $host === 'localhost' ? [['ip' => '127.0.0.1']] : [['ip' => '93.184.216.34']],
        ));

        $this->put('/settings/socialreins', $this->payload(['base_url' => $url]))->assertSessionHasErrors('base_url');
        $this->assertNull($workspace->fresh()->settings['socialreins'] ?? null);
    }

    public function test_a_host_that_resolves_to_a_private_address_is_refused(): void
    {
        $workspace = Workspace::factory()->create();
        $this->memberOf($workspace, 'owner');
        $this->resolveHostsTo('10.1.2.3');

        $this->put('/settings/socialreins', $this->payload())->assertSessionHasErrors('base_url');
    }

    public function test_changing_the_base_url_clears_tokens_and_webhook_secret_but_a_trailing_slash_does_not(): void
    {
        $workspace = Workspace::factory()->create();
        $this->memberOf($workspace, 'owner');
        $this->put('/settings/socialreins', $this->payload())->assertRedirect();

        $this->put('/settings/socialreins', $this->payload([
            'base_url' => 'https://brain.socialreins.com/v1/', 'webhook_secret' => '',
            'languages' => ['english' => ['set_id' => self::SET, 'token' => '']],
        ]))->assertRedirect();
        $config = SocialreinsConfig::fromWorkspace($workspace->fresh());
        $this->assertSame('sr_set_live_secret', $config->token('english'));
        $this->assertSame('https://brain.socialreins.com/v1', $config->baseUrl());

        $this->put('/settings/socialreins', $this->payload([
            'base_url' => 'https://brain.self-host.example/v1', 'webhook_secret' => '',
            'languages' => ['english' => ['set_id' => self::SET, 'token' => '']],
        ]))->assertRedirect();
        $config = SocialreinsConfig::fromWorkspace($workspace->fresh());
        $this->assertNull($config->token('english'));
        $this->assertNull($config->webhookSecret());
        $this->assertSame(self::SET, $config->setId('english'));
        $this->assertFalse($config->isReadyForPublish());
    }
}
