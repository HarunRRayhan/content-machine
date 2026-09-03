<?php

namespace Tests\Unit\Support\AiProviders;

use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialModel;
use App\Support\AiProviders\HttpAiCompletionClient;
use App\Support\LinkResolution\PublicUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpAiCompletionClientTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $credentialAttrs
     */
    private function entry(array $credentialAttrs = [], string $model = 'claude-sonnet-4-5'): AiProviderCredentialModel
    {
        $credential = AiProviderCredential::factory()->make($credentialAttrs);
        $entry = AiProviderCredentialModel::factory()->make(['model' => $model]);
        $entry->setRelation('credential', $credential);

        return $entry;
    }

    public function test_anthropic_success_hits_the_default_base_url_with_the_right_shape()
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'A short summary.']],
        ], 200)]);

        $entry = $this->entry([
            'provider' => 'anthropic',
            'base_url' => null,
            'api_key' => 'sk-ant-test',
        ], 'claude-sonnet-4-5');

        $result = (new HttpAiCompletionClient)->complete($entry, 'system prompt', 'user content');

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

        $entry = $this->entry(['provider' => 'openai', 'api_key' => 'sk-openai-test'], 'gpt-4o');

        $result = (new HttpAiCompletionClient)->complete($entry, 'system prompt', 'user content');

        $this->assertTrue($result->successful);
        $this->assertSame('A short summary.', $result->text);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-openai-test')
            && $request['model'] === 'gpt-4o'
            && $request['max_tokens'] === 1000
            && $request['messages'][0]['role'] === 'system'
            && $request['messages'][0]['content'] === 'system prompt'
            && $request['messages'][1]['content'] === 'user content');
    }

    public function test_anthropic_vision_success_sends_a_base64_image_content_block(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"title":"Photo"}']],
        ], 200)]);

        $entry = $this->entry([
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
        ]);

        $result = (new HttpAiCompletionClient)->completeWithImage(
            $entry,
            'system prompt',
            'caption or instruction',
            'image/jpeg',
            'image-bytes',
        );

        $this->assertTrue($result->successful);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request['messages'][0]['content'][0]['type'] === 'image'
            && $request['messages'][0]['content'][0]['source']['media_type'] === 'image/jpeg'
            && $request['messages'][0]['content'][0]['source']['data'] === base64_encode('image-bytes')
            && $request['messages'][0]['content'][1]['text'] === 'caption or instruction');
    }

    public function test_openai_vision_success_sends_a_data_url_image(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => '{"title":"Photo"}']]],
        ], 200)]);

        $entry = $this->entry(['provider' => 'openai', 'api_key' => 'sk-openai-test'], 'gpt-4o');

        $result = (new HttpAiCompletionClient)->completeWithImage(
            $entry,
            'system prompt',
            'caption or instruction',
            'image/png',
            'image-bytes',
        );

        $this->assertTrue($result->successful);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request['messages'][1]['content'][0]['text'] === 'caption or instruction'
            && $request['messages'][1]['content'][1]['image_url']['url'] === 'data:image/png;base64,'.base64_encode('image-bytes'));
    }

    public function test_a_custom_base_url_is_used_when_given()
    {
        Http::fake(['*' => Http::response(['content' => [['text' => 'ok']]], 200)]);

        $entry = $this->entry([
            'provider' => 'anthropic',
            'base_url' => 'https://proxy.example.com/anthropic',
        ]);

        (new HttpAiCompletionClient(new PublicUrlGuard(
            fn (string $host): array => [['ip' => '1.1.1.1']],
        )))->complete($entry, 'sys', 'user');

        Http::assertSent(fn ($request) => $request->url() === 'https://proxy.example.com/anthropic/v1/messages');
    }

    public function test_a_custom_base_url_resolving_to_a_private_ip_is_rejected(): void
    {
        Http::fake();

        $entry = $this->entry([
            'provider' => 'anthropic',
            'base_url' => 'https://internal.example/anthropic',
        ]);

        $result = (new HttpAiCompletionClient(new PublicUrlGuard(
            fn (string $host): array => [['ip' => '127.0.0.1']],
        )))->complete($entry, 'sys', 'user');

        $this->assertFalse($result->successful);
        $this->assertSame('Could not reach the completion provider.', $result->error);
        Http::assertNothingSent();
    }

    public function test_a_provider_error_message_is_surfaced_when_present()
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'rate limited']], 429)]);

        $entry = $this->entry(['provider' => 'anthropic']);

        $result = (new HttpAiCompletionClient)->complete($entry, 'sys', 'user');

        $this->assertFalse($result->successful);
        $this->assertSame('rate limited', $result->error);
    }

    public function test_a_status_with_no_error_message_gets_a_generic_message()
    {
        Http::fake(['*' => Http::response([], 500)]);

        $entry = $this->entry(['provider' => 'openai']);

        $result = (new HttpAiCompletionClient)->complete($entry, 'sys', 'user');

        $this->assertFalse($result->successful);
        $this->assertSame('The completion provider returned an unexpected status (500).', $result->error);
    }

    public function test_empty_text_is_reported_as_a_failure()
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '   ']]]], 200)]);

        $entry = $this->entry(['provider' => 'openai']);

        $result = (new HttpAiCompletionClient)->complete($entry, 'sys', 'user');

        $this->assertFalse($result->successful);
        $this->assertSame('The completion provider returned no text.', $result->error);
    }

    public function test_a_connection_failure_is_reported_without_leaking_the_exception()
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $entry = $this->entry(['provider' => 'anthropic']);

        $result = (new HttpAiCompletionClient)->complete($entry, 'sys', 'user');

        $this->assertFalse($result->successful);
        $this->assertSame('Could not reach the completion provider.', $result->error);
    }
}
