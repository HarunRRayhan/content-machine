<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\ResolveTelegramIntentAction;
use App\Models\AiProviderCredential;
use App\Models\Workspace;
use App\Support\AiProviders\AiCompletionClientContract;
use App\Support\AiProviders\AiCompletionResult;
use App\Support\AiProviders\AiProviderCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ResolveTelegramIntentActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_recognized_intent_is_returned()
    {
        $workspace = Workspace::factory()->create();
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::success('notes');
            }
        };

        $intent = (new ResolveTelegramIntentAction($client, new AiProviderCredentialResolver))
            ->handle($workspace, 'show me my scratchpad');

        $this->assertSame('notes', $intent);
    }

    public function test_a_delete_request_resolves_to_clear_notes()
    {
        $workspace = Workspace::factory()->create();
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::success('clear_notes');
            }
        };

        $intent = (new ResolveTelegramIntentAction($client, new AiProviderCredentialResolver))
            ->handle($workspace, 'delete all of these');

        $this->assertSame('clear_notes', $intent);
    }

    public function test_the_model_saying_none_returns_null()
    {
        $workspace = Workspace::factory()->create();
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::success('none');
            }
        };

        $intent = (new ResolveTelegramIntentAction($client, new AiProviderCredentialResolver))
            ->handle($workspace, 'how is your day going?');

        $this->assertNull($intent);
    }

    public function test_an_unrecognized_word_from_the_model_is_treated_as_no_intent()
    {
        $workspace = Workspace::factory()->create();
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::success('Sure, happy to help!');
            }
        };

        $intent = (new ResolveTelegramIntentAction($client, new AiProviderCredentialResolver))
            ->handle($workspace, 'hey there');

        $this->assertNull($intent);
    }

    public function test_matching_is_case_insensitive_and_trims_whitespace()
    {
        $workspace = Workspace::factory()->create();
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::success(" Videos \n");
            }
        };

        $intent = (new ResolveTelegramIntentAction($client, new AiProviderCredentialResolver))
            ->handle($workspace, 'what videos do I have?');

        $this->assertSame('videos', $intent);
    }

    public function test_no_provider_configured_returns_null()
    {
        $workspace = Workspace::factory()->create();

        $client = new class implements AiCompletionClientContract
        {
            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                throw new RuntimeException('should never be called');
            }
        };

        $intent = (new ResolveTelegramIntentAction($client, new AiProviderCredentialResolver))
            ->handle($workspace, 'show me my posts');

        $this->assertNull($intent);
    }

    public function test_every_credential_failing_returns_null()
    {
        $workspace = Workspace::factory()->create();
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id, 'priority' => 0]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id, 'priority' => 1]);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::failure('provider down');
            }
        };

        $intent = (new ResolveTelegramIntentAction($client, new AiProviderCredentialResolver))
            ->handle($workspace, 'show me my posts');

        $this->assertNull($intent);
    }

    public function test_the_fallback_chain_tries_the_next_credential_after_a_failure()
    {
        $workspace = Workspace::factory()->create();
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id, 'priority' => 0, 'api_key' => 'sk-first']);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id, 'priority' => 1, 'api_key' => 'sk-second']);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                return $entry->credential->api_key === 'sk-first'
                    ? AiCompletionResult::failure('first provider is down')
                    : AiCompletionResult::success('posts');
            }
        };

        $intent = (new ResolveTelegramIntentAction($client, new AiProviderCredentialResolver))
            ->handle($workspace, 'show me my posts');

        $this->assertSame('posts', $intent);
    }
}
