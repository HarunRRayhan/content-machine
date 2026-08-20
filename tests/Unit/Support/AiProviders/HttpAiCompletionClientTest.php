<?php

namespace Tests\Unit\Support\AiProviders;

use App\Models\AiProviderCredential;
use App\Support\AiProviders\HttpAiCompletionClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpAiCompletionClientTest extends TestCase
{
    public function test_anthropic_success_hits_the_default_base_url_with_the_right_shape()
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'A short summary.']],
        ], 200)]);

        $credential = AiProviderCredential::factory()->make([
            'provider' => 'anthropic',
            'base_url' => null,
            'api_key' => 'sk-ant-test',
            'model' => 'claude-sonnet-4-5',
        ]);

        $result = (new HttpAiCompletionClient)->complete($credential, 'system prompt', 'user content');

        $this->assertTrue($result->successful);
        $this->assertSame('A short summary.', $result->text);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'sk-ant-test')
            && $request->hasHeader('anthropic-version')
            && $request['model'] === 'claude-sonnet-4-5'
            && $request['system'] === 'system prompt'
            && $request['messages'][0]['content'] === 'user content');
    }

    public function test_openai_success_uses_bearer_auth_and_chat_completions_shape()
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'A short summary.']]],
        ], 200)]);

        $credential = AiProviderCredential::factory()->openai()->make(['api_key' => 'sk-openai-test', 'model' => 'gpt-4o']);

        $result = (new HttpAiCompletionClient)->complete($credential, 'system prompt', 'user content');

        $this->assertTrue($result->successful);
        $this->assertSame('A short summary.', $result->text);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-openai-test')
            && $request['model'] === 'gpt-4o'
            && $request['messages'][0]['role'] === 'system'
            && $request['messages'][0]['content'] === 'system prompt'
            && $request['messages'][1]['content'] === 'user content');
    }

    public function test_a_custom_base_url_is_used_when_given()
    {
        Http::fake(['*' => Http::response(['content' => [['text' => 'ok']]], 200)]);

        $credential = AiProviderCredential::factory()->make([
            'provider' => 'anthropic',
            'base_url' => 'https://proxy.example.com/anthropic',
        ]);

        (new HttpAiCompletionClient)->complete($credential, 'sys', 'user');

        Http::assertSent(fn ($request) => $request->url() === 'https://proxy.example.com/anthropic/v1/messages');
    }

    public function test_a_provider_error_message_is_surfaced_when_present()
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'rate limited']], 429)]);

        $credential = AiProviderCredential::factory()->make(['provider' => 'anthropic']);

        $result = (new HttpAiCompletionClient)->complete($credential, 'sys', 'user');

        $this->assertFalse($result->successful);
        $this->assertSame('rate limited', $result->error);
    }

    public function test_a_status_with_no_error_message_gets_a_generic_message()
    {
        Http::fake(['*' => Http::response([], 500)]);

        $credential = AiProviderCredential::factory()->openai()->make();

        $result = (new HttpAiCompletionClient)->complete($credential, 'sys', 'user');

        $this->assertFalse($result->successful);
        $this->assertSame('The completion provider returned an unexpected status (500).', $result->error);
    }

    public function test_empty_text_is_reported_as_a_failure()
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '   ']]]], 200)]);

        $credential = AiProviderCredential::factory()->openai()->make();

        $result = (new HttpAiCompletionClient)->complete($credential, 'sys', 'user');

        $this->assertFalse($result->successful);
        $this->assertSame('The completion provider returned no text.', $result->error);
    }

    public function test_a_connection_failure_is_reported_without_leaking_the_exception()
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $credential = AiProviderCredential::factory()->make(['provider' => 'anthropic']);

        $result = (new HttpAiCompletionClient)->complete($credential, 'sys', 'user');

        $this->assertFalse($result->successful);
        $this->assertSame('Could not reach the completion provider.', $result->error);
    }
}
