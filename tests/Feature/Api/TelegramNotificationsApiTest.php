<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Jobs\SendTelegramOutboundMessageJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramOutboundMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramNotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private string $token;

    private TelegramBotConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->workspace = Workspace::factory()->create();
        $this->owner = User::factory()->create();
        $this->addMember($this->owner);
        $this->token = $this->mintToken($this->owner);
        $this->config = TelegramBotConfig::factory()->connected()->create([
            'workspace_id' => $this->workspace->id,
        ]);
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $this->config->id,
            'user_id' => $this->owner->id,
            'telegram_user_id' => 123456789,
        ]);
    }

    public function test_it_queues_to_the_token_owners_link_and_returns_safe_status(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/notifications/telegram', [
                'key' => 'nudge-42',
                'text' => 'Hello Harun',
                'chat_id' => 999999999,
            ])
            ->assertStatus(202)
            ->assertJsonPath('key', 'nudge-42')
            ->assertJsonPath('status', TelegramOutboundMessage::PENDING)
            ->assertJsonPath('sent_at', null)
            ->assertJsonMissingPath('chat_id');

        $message = TelegramOutboundMessage::query()->sole();
        $this->assertSame(123456789, $message->chat_id);
        $this->assertSame('api-notification:user:'.$this->owner->id.':nudge-42', $message->logical_key);
        $this->assertSame(['Hello Harun'], $message->chunks);
        Queue::assertPushed(SendTelegramOutboundMessageJob::class);

        $this->withToken($this->token)
            ->getJson('/api/v1/notifications/telegram/nudge-42')
            ->assertOk()
            ->assertJsonPath('key', 'nudge-42')
            ->assertJsonPath('status', TelegramOutboundMessage::PENDING)
            ->assertJsonMissingPath('chat_id')
            ->assertJsonMissingPath('last_error');
    }

    public function test_identical_requests_are_idempotent_and_changed_content_conflicts(): void
    {
        $payload = ['key' => 'daily-nudge', 'text' => 'Pick a topic'];

        $this->withToken($this->token)->postJson('/api/v1/notifications/telegram', $payload)->assertStatus(202);
        $this->withToken($this->token)->postJson('/api/v1/notifications/telegram', $payload)->assertOk();
        $this->assertSame(1, TelegramOutboundMessage::query()->count());
        Queue::assertPushed(SendTelegramOutboundMessageJob::class, 1);

        $this->withToken($this->token)
            ->postJson('/api/v1/notifications/telegram', ['key' => 'daily-nudge', 'text' => 'Different copy'])
            ->assertConflict();
        $this->assertSame(1, TelegramOutboundMessage::query()->count());
    }

    public function test_a_key_is_scoped_to_the_token_owner_and_status_is_not_visible_to_another_owner(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/notifications/telegram', ['key' => 'shared-key', 'text' => 'Owner one'])
            ->assertStatus(202);

        $otherOwner = User::factory()->create();
        $this->addMember($otherOwner);
        $otherToken = $this->mintToken($otherOwner);
        TelegramBotLink::factory()->create([
            'telegram_bot_config_id' => $this->config->id,
            'user_id' => $otherOwner->id,
            'telegram_user_id' => 987654321,
        ]);

        $this->withToken($otherToken)
            ->getJson('/api/v1/notifications/telegram/shared-key')
            ->assertNotFound();

        $this->withToken($otherToken)
            ->postJson('/api/v1/notifications/telegram', ['key' => 'shared-key', 'text' => 'Owner two'])
            ->assertStatus(202);

        $this->assertSame(2, TelegramOutboundMessage::query()->count());
    }

    public function test_write_and_read_abilities_are_separate(): void
    {
        $writeOnly = $this->mintToken($this->owner, ['notifications:write']);
        $readOnly = $this->mintToken($this->owner, ['notifications:read']);

        $this->withToken($writeOnly)
            ->postJson('/api/v1/notifications/telegram', ['key' => 'scoped', 'text' => 'Message'])
            ->assertStatus(202);
        $this->withToken($writeOnly)->getJson('/api/v1/notifications/telegram/scoped')->assertForbidden();
        $this->withToken($readOnly)->getJson('/api/v1/notifications/telegram/scoped')->assertOk();
        $this->withToken($readOnly)
            ->postJson('/api/v1/notifications/telegram', ['key' => 'not-allowed', 'text' => 'Message'])
            ->assertForbidden();
    }

    public function test_a_revoked_workspace_token_cannot_create_or_read_notifications(): void
    {
        $token = WorkspaceApiToken::query()->where('created_by_user_id', $this->owner->id)->firstOrFail();
        $token->forceFill(['revoked_at' => now()])->save();

        $this->withToken($this->token)
            ->postJson('/api/v1/notifications/telegram', ['key' => 'revoked', 'text' => 'Message'])
            ->assertUnauthorized();
        $this->withToken($this->token)
            ->getJson('/api/v1/notifications/telegram/revoked')
            ->assertUnauthorized();
    }

    public function test_create_rejects_unlinked_disconnected_and_nonmember_owners(): void
    {
        $unlinked = User::factory()->create();
        $this->addMember($unlinked);
        $unlinkedToken = $this->mintToken($unlinked);
        $this->withToken($unlinkedToken)
            ->postJson('/api/v1/notifications/telegram', ['key' => 'unlinked', 'text' => 'Message'])
            ->assertConflict();

        $this->config->forceFill(['bot_token' => null])->save();
        $this->withToken($this->token)
            ->postJson('/api/v1/notifications/telegram', ['key' => 'disconnected', 'text' => 'Message'])
            ->assertConflict();

        $this->config->forceFill([
            'bot_token' => '123:token',
            'connection_operation' => TelegramBotConfig::CONNECTING,
        ])->save();
        $this->withToken($this->token)
            ->postJson('/api/v1/notifications/telegram', ['key' => 'connecting', 'text' => 'Message'])
            ->assertConflict();

        $this->config->forceFill(['connection_operation' => null])->save();
        DB::table('team_user')
            ->where('team_id', $this->workspace->team_id)
            ->where('user_id', $this->owner->id)
            ->delete();
        $this->withToken($this->token)
            ->postJson('/api/v1/notifications/telegram', ['key' => 'removed', 'text' => 'Message'])
            ->assertForbidden();
    }

    public function test_status_remains_readable_after_unlink_but_not_after_membership_removal(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/notifications/telegram', ['key' => 'history', 'text' => 'Message'])
            ->assertStatus(202);

        TelegramBotLink::query()->where('user_id', $this->owner->id)->delete();
        $this->withToken($this->token)->getJson('/api/v1/notifications/telegram/history')->assertOk();

        DB::table('team_user')
            ->where('team_id', $this->workspace->team_id)
            ->where('user_id', $this->owner->id)
            ->delete();
        $this->withToken($this->token)->getJson('/api/v1/notifications/telegram/history')->assertForbidden();
    }

    public function test_status_does_not_cross_workspace_boundaries(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/notifications/telegram', ['key' => 'private', 'text' => 'Message'])
            ->assertStatus(202);

        $otherWorkspace = Workspace::factory()->create();
        $this->addMember($this->owner, $otherWorkspace);
        $otherToken = $this->mintToken($this->owner, ['notifications:read'], $otherWorkspace);

        $this->withToken($otherToken)->getJson('/api/v1/notifications/telegram/private')->assertNotFound();
    }

    public function test_a_reused_key_after_reconnect_returns_original_record_without_resending(): void
    {
        $payload = ['key' => 'reconnect-safe', 'text' => 'Same message'];
        $this->withToken($this->token)->postJson('/api/v1/notifications/telegram', $payload)->assertStatus(202);

        $this->config->forceFill([
            'webhook_generation' => (string) Str::uuid(),
        ])->save();

        $this->withToken($this->token)->postJson('/api/v1/notifications/telegram', $payload)->assertOk();
        $this->assertSame(1, TelegramOutboundMessage::query()->count());
        Queue::assertPushed(SendTelegramOutboundMessageJob::class, 1);
    }

    public function test_reusing_a_key_after_the_owner_relinks_to_a_different_chat_conflicts(): void
    {
        $payload = ['key' => 'chat-change', 'text' => 'Same message'];
        $this->withToken($this->token)->postJson('/api/v1/notifications/telegram', $payload)->assertStatus(202);

        TelegramBotLink::query()
            ->where('telegram_bot_config_id', $this->config->id)
            ->where('user_id', $this->owner->id)
            ->firstOrFail()
            ->forceFill(['telegram_user_id' => 333444555])
            ->save();

        $this->withToken($this->token)->postJson('/api/v1/notifications/telegram', $payload)->assertConflict();
        $this->assertSame(1, TelegramOutboundMessage::query()->count());
        Queue::assertPushed(SendTelegramOutboundMessageJob::class, 1);
    }

    private function addMember(User $user, ?Workspace $workspace = null): void
    {
        $workspace ??= $this->workspace;
        DB::table('team_user')->insertOrIgnore([
            'team_id' => $workspace->team_id,
            'user_id' => $user->id,
            'role' => 'member',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<string> $abilities */
    private function mintToken(User $owner, array $abilities = ['notifications:read', 'notifications:write'], ?Workspace $workspace = null): string
    {
        return (new CreateWorkspaceApiTokenAction)->handle(
            $workspace ?? $this->workspace,
            $owner,
            new CreateWorkspaceApiTokenData('notification test', $abilities),
        )['plaintext'];
    }
}
