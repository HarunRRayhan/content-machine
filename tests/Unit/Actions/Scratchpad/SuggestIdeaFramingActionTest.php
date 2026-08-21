<?php

namespace Tests\Unit\Actions\Scratchpad;

use App\Actions\Scratchpad\SuggestIdeaFramingAction;
use App\Models\AiProviderCredential;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use App\Models\Workspace;
use App\Support\AiProviders\AiCompletionClientContract;
use App\Support\AiProviders\AiCompletionResult;
use App\Support\AiProviders\AiProviderCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuggestIdeaFramingActionTest extends TestCase
{
    use RefreshDatabase;

    private function fakeClient(AiCompletionResult $result): AiCompletionClientContract
    {
        return new class($result) implements AiCompletionClientContract
        {
            public function __construct(private readonly AiCompletionResult $result) {}

            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                return $this->result;
            }
        };
    }

    public function test_a_valid_json_suggestion_is_parsed()
    {
        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Title', 'body' => 'Body']);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = $this->fakeClient(AiCompletionResult::success(json_encode([
            'title' => 'A great post idea',
            'score' => 850,
            'trend' => 'evergreen',
            'rationale' => 'Because it teaches something durable.',
        ])));

        $suggestion = (new SuggestIdeaFramingAction($client, new AiProviderCredentialResolver))->handle($entry, 'post');

        $this->assertTrue($suggestion->successful);
        $this->assertSame('A great post idea', $suggestion->title);
        $this->assertSame(850, $suggestion->score);
        $this->assertSame('evergreen', $suggestion->trend);
        $this->assertSame('Because it teaches something durable.', $suggestion->rationale);
    }

    public function test_markdown_fenced_json_is_rejected_as_unparseable()
    {
        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->create(['workspace_id' => $workspace->id]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = $this->fakeClient(AiCompletionResult::success('```json'.PHP_EOL.'{"title":"x","score":1,"trend":"evergreen","rationale":"y"}'.PHP_EOL.'```'));

        $suggestion = (new SuggestIdeaFramingAction($client, new AiProviderCredentialResolver))->handle($entry, 'post');

        $this->assertFalse($suggestion->successful);
    }

    public function test_an_out_of_range_score_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->create(['workspace_id' => $workspace->id]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = $this->fakeClient(AiCompletionResult::success(json_encode([
            'title' => 'x', 'score' => 1500, 'trend' => 'evergreen', 'rationale' => 'y',
        ])));

        $suggestion = (new SuggestIdeaFramingAction($client, new AiProviderCredentialResolver))->handle($entry, 'post');

        $this->assertFalse($suggestion->successful);
    }

    public function test_an_invalid_trend_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->create(['workspace_id' => $workspace->id]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = $this->fakeClient(AiCompletionResult::success(json_encode([
            'title' => 'x', 'score' => 1, 'trend' => 'trending', 'rationale' => 'y',
        ])));

        $suggestion = (new SuggestIdeaFramingAction($client, new AiProviderCredentialResolver))->handle($entry, 'post');

        $this->assertFalse($suggestion->successful);
    }

    public function test_no_provider_configured_fails_honestly()
    {
        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiCompletionClientContract
        {
            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                throw new \RuntimeException('should never be called');
            }
        };

        $suggestion = (new SuggestIdeaFramingAction($client, new AiProviderCredentialResolver))->handle($entry, 'post');

        $this->assertFalse($suggestion->successful);
        $this->assertSame('No AI-generated suggestion is available right now.', $suggestion->error);
    }

    public function test_the_fallback_chain_tries_the_next_credential_after_unparseable_output()
    {
        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->create(['workspace_id' => $workspace->id]);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id, 'priority' => 0, 'api_key' => 'sk-first']);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id, 'priority' => 1, 'api_key' => 'sk-second']);

        $client = new class implements AiCompletionClientContract
        {
            public array $attemptedKeys = [];

            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                $this->attemptedKeys[] = $entry->credential->api_key;

                return $entry->credential->api_key === 'sk-first'
                    ? AiCompletionResult::success('not json at all')
                    : AiCompletionResult::success(json_encode([
                        'title' => 'from second', 'score' => 10, 'trend' => 'seasonal', 'rationale' => 'r',
                    ]));
            }
        };

        $suggestion = (new SuggestIdeaFramingAction($client, new AiProviderCredentialResolver))->handle($entry, 'video');

        $this->assertSame(['sk-first', 'sk-second'], $client->attemptedKeys);
        $this->assertTrue($suggestion->successful);
        $this->assertSame('from second', $suggestion->title);
    }

    public function test_the_prompt_includes_the_target_kind_and_transcript()
    {
        $workspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->create(['workspace_id' => $workspace->id, 'kind' => 'voice', 'title' => null, 'body' => null]);
        $entry->transcriptions()->create(['media_asset_id' => MediaAsset::factory()->create(['workspace_id' => $workspace->id])->id, 'status' => 'done', 'text' => 'A spoken transcript.']);
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = new class implements AiCompletionClientContract
        {
            public ?string $capturedUserContent = null;

            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                $this->capturedUserContent = $userContent;

                return AiCompletionResult::success(json_encode([
                    'title' => 't', 'score' => 1, 'trend' => 'evergreen', 'rationale' => 'r',
                ]));
            }
        };

        (new SuggestIdeaFramingAction($client, new AiProviderCredentialResolver))->handle($entry->fresh(), 'video');

        $this->assertStringContainsString('Considering this as a video idea.', $client->capturedUserContent);
        $this->assertStringContainsString('A spoken transcript.', $client->capturedUserContent);
    }
}
