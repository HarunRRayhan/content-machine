<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\GenerateTelegramChatReplyAction;
use App\Models\AiProviderCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AiProviders\AiCompletionClientContract;
use App\Support\AiProviders\AiCompletionResult;
use App\Support\AiProviders\AiProviderCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateTelegramChatReplyActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_completion_returns_its_text()
    {
        $workspace = Workspace::factory()->create(['name' => 'Acme']);
        $user = User::factory()->create(['name' => 'Ada Lovelace']);
        AiProviderCredential::factory()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::success('Sure, happy to brainstorm.');
            }
        };

        $reply = (new GenerateTelegramChatReplyAction($client, new AiProviderCredentialResolver))
            ->handle($workspace, $user, 'got any post ideas?');

        $this->assertSame('Sure, happy to brainstorm.', $reply);
    }

    public function test_the_system_prompt_includes_the_users_name_and_workspace()
    {
        $workspace = Workspace::factory()->create(['name' => 'Acme Workspace']);
        $user = User::factory()->create(['name' => 'Ada Lovelace']);
        AiProviderCredential::factory()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiCompletionClientContract
        {
            public ?string $capturedPrompt = null;

            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                $this->capturedPrompt = $systemPrompt;

                return AiCompletionResult::success('ok');
            }
        };

        (new GenerateTelegramChatReplyAction($client, new AiProviderCredentialResolver))
            ->handle($workspace, $user, 'hey');

        $this->assertStringContainsString('Ada Lovelace', $client->capturedPrompt);
        $this->assertStringContainsString('Acme Workspace', $client->capturedPrompt);
        $this->assertStringContainsString('no access', $client->capturedPrompt);
    }

    public function test_no_provider_configured_returns_null()
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        $client = new class implements AiCompletionClientContract
        {
            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                throw new \RuntimeException('should never be called');
            }
        };

        $reply = (new GenerateTelegramChatReplyAction($client, new AiProviderCredentialResolver))
            ->handle($workspace, $user, 'hey');

        $this->assertNull($reply);
    }

    public function test_every_credential_failing_returns_null()
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        AiProviderCredential::factory()->create(['workspace_id' => $workspace->id, 'priority' => 0]);
        AiProviderCredential::factory()->create(['workspace_id' => $workspace->id, 'priority' => 1]);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::failure('provider down');
            }
        };

        $reply = (new GenerateTelegramChatReplyAction($client, new AiProviderCredentialResolver))
            ->handle($workspace, $user, 'hey');

        $this->assertNull($reply);
    }

    public function test_the_fallback_chain_tries_the_next_credential_after_a_failure()
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        AiProviderCredential::factory()->create(['workspace_id' => $workspace->id, 'priority' => 0, 'api_key' => 'sk-first']);
        AiProviderCredential::factory()->create(['workspace_id' => $workspace->id, 'priority' => 1, 'api_key' => 'sk-second']);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                return $credential->api_key === 'sk-first'
                    ? AiCompletionResult::failure('first provider is down')
                    : AiCompletionResult::success('from the second provider');
            }
        };

        $reply = (new GenerateTelegramChatReplyAction($client, new AiProviderCredentialResolver))
            ->handle($workspace, $user, 'hey');

        $this->assertSame('from the second provider', $reply);
    }
}
