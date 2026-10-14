<?php

namespace Tests\Unit\Actions\Telegram;

use App\Actions\Telegram\ResolveTelegramWorkspaceActionAction;
use App\Models\AiProviderCredential;
use App\Models\Workspace;
use App\Support\AiProviders\AiCompletionClientContract;
use App\Support\AiProviders\AiCompletionResult;
use App\Support\AiProviders\AiProviderCredentialResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveTelegramWorkspaceActionActionTest extends TestCase
{
    use RefreshDatabase;

    private function resolve(string $modelText): ?array
    {
        $workspace = Workspace::factory()->create();
        AiProviderCredential::factory()->withModel()->create(['workspace_id' => $workspace->id]);

        $client = new class($modelText) implements AiCompletionClientContract
        {
            public function __construct(private readonly string $text) {}

            public function complete($entry, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::success($this->text);
            }
        };

        return (new ResolveTelegramWorkspaceActionAction($client, new AiProviderCredentialResolver))->handle($workspace, 'anything');
    }

    public function test_a_fenced_json_action_is_parsed()
    {
        $action = $this->resolve("```json\n{\"action\":\"create_scratchpad\",\"body\":\"hi\"}\n```");

        $this->assertSame(['action' => 'create_scratchpad', 'body' => 'hi'], $action);
    }

    public function test_raw_line_breaks_in_a_dictated_body_do_not_break_parsing()
    {
        $action = $this->resolve("{\"action\":\"create_scratchpad\",\"body\":\"line one\nline two\"}");

        $this->assertSame('create_scratchpad', $action['action']);
        $this->assertSame('line one line two', $action['body']);
    }

    public function test_none_prose_and_unknown_actions_return_null()
    {
        $this->assertNull($this->resolve('none'));
        $this->assertNull($this->resolve('Sure, I saved it!'));
        $this->assertNull($this->resolve('{"action":"publish_post","id":"P-1"}'));
    }
}
