<?php

namespace Tests\Unit\Actions\Scratchpad;

use App\Actions\Scratchpad\SummarizeCaptureAction;
use App\Models\AiProviderCredential;
use App\Models\ScratchpadEntry;
use App\Models\Workspace;
use App\Support\AiProviders\AiCompletionClientContract;
use App\Support\AiProviders\AiCompletionResult;
use App\Support\AiProviders\AiProviderCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SummarizeCaptureActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_summary_overwrites_the_body_and_records_when()
    {
        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'kind' => 'link',
            'title' => 'A Great Article',
            'body' => 'The raw scraped og:description.',
            'meta' => ['url' => 'https://example.com/post', 'resolved_kind' => 'webpage'],
        ]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::success('A punchy AI summary.');
            }
        };

        (new SummarizeCaptureAction($client, new AiProviderCredentialResolver))->handle($entry);

        $entry->refresh();
        $this->assertSame('A punchy AI summary.', $entry->body);
        $this->assertNotNull($entry->meta['summarized_at']);
        $this->assertSame('https://example.com/post', $entry->meta['url']);
    }

    public function test_the_prompt_includes_title_description_and_url()
    {
        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'A Great Article',
            'body' => 'The raw description.',
            'meta' => ['url' => 'https://example.com/post'],
        ]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiCompletionClientContract
        {
            public ?string $capturedUserContent = null;

            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                $this->capturedUserContent = $userContent;

                return AiCompletionResult::success('summary');
            }
        };

        (new SummarizeCaptureAction($client, new AiProviderCredentialResolver))->handle($entry);

        $this->assertStringContainsString('A Great Article', $client->capturedUserContent);
        $this->assertStringContainsString('The raw description.', $client->capturedUserContent);
        $this->assertStringContainsString('https://example.com/post', $client->capturedUserContent);
    }

    public function test_no_provider_configured_leaves_the_body_untouched()
    {
        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Title',
            'body' => 'The raw scraped description.',
        ]);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                throw new RuntimeException('should never be called');
            }
        };

        (new SummarizeCaptureAction($client, new AiProviderCredentialResolver))->handle($entry);

        $this->assertSame('The raw scraped description.', $entry->refresh()->body);
        $this->assertArrayNotHasKey('summarized_at', $entry->meta);
    }

    public function test_no_title_or_description_skips_without_touching_meta()
    {
        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => null,
            'body' => null,
        ]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                throw new RuntimeException('should never be called');
            }
        };

        (new SummarizeCaptureAction($client, new AiProviderCredentialResolver))->handle($entry);

        $this->assertNull($entry->refresh()->body);
    }

    public function test_the_fallback_chain_tries_the_next_credential_after_a_failure()
    {
        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Title',
            'body' => 'Description.',
            'meta' => [],
        ]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id, 'priority' => 0, 'api_key' => 'sk-first']);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id, 'priority' => 1, 'api_key' => 'sk-second']);

        $client = new class implements AiCompletionClientContract
        {
            public array $attemptedKeys = [];

            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                $this->attemptedKeys[] = $entry->credential->api_key;

                return $entry->credential->api_key === 'sk-first'
                    ? AiCompletionResult::failure('first provider is down')
                    : AiCompletionResult::success('from the second provider');
            }
        };

        (new SummarizeCaptureAction($client, new AiProviderCredentialResolver))->handle($entry);

        $this->assertSame(['sk-first', 'sk-second'], $client->attemptedKeys);
        $this->assertSame('from the second provider', $entry->refresh()->body);
    }

    public function test_exhausting_every_credential_leaves_the_body_untouched()
    {
        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Title',
            'body' => 'The raw scraped description.',
        ]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::failure('provider is down');
            }
        };

        (new SummarizeCaptureAction($client, new AiProviderCredentialResolver))->handle($entry);

        $this->assertSame('The raw scraped description.', $entry->refresh()->body);
        $this->assertArrayNotHasKey('summarized_at', $entry->meta);
    }
}
