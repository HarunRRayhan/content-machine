<?php

namespace Tests\Unit\Support\AiProviders;

use App\Models\AiProviderCredential;
use App\Support\AiProviders\HttpAiProviderVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpAiProviderVerifierTest extends TestCase
{
    public function test_anthropic_success_hits_the_default_base_url_with_the_right_headers()
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['data' => []], 200)]);

        $credential = AiProviderCredential::factory()->make([
            'provider' => 'anthropic',
            'base_url' => null,
            'api_key' => 'sk-ant-test',
        ]);

        $result = (new HttpAiProviderVerifier)->verify($credential);

        $this->assertTrue($result->successful);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.anthropic.com/v1/models'
            && $request->hasHeader('x-api-key', 'sk-ant-test')
            && $request->hasHeader('anthropic-version'));
    }

    public function test_anthropic_uses_a_custom_base_url_when_given()
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $credential = AiProviderCredential::factory()->make([
            'provider' => 'anthropic',
            'base_url' => 'https://proxy.example.com/anthropic',
        ]);

        (new HttpAiProviderVerifier)->verify($credential);

        Http::assertSent(fn ($request) => $request->url() === 'https://proxy.example.com/anthropic/v1/models');
    }

    public function test_openai_success_uses_bearer_auth()
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => []], 200)]);

        $credential = AiProviderCredential::factory()->openai()->make(['api_key' => 'sk-openai-test']);

        $result = (new HttpAiProviderVerifier)->verify($credential);

        $this->assertTrue($result->successful);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/models'
            && $request->hasHeader('Authorization', 'Bearer sk-openai-test'));
    }

    public function test_openai_style_custom_base_url_already_carrying_v1_hits_a_bare_models_path()
    {
        Http::fake(['openrouter.ai/*' => Http::response(['data' => []], 200)]);

        $credential = AiProviderCredential::factory()->openai()->make(['base_url' => 'https://openrouter.ai/api/v1']);

        (new HttpAiProviderVerifier)->verify($credential);

        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.ai/api/v1/models');
    }

    public function test_anthropic_models_are_parsed_with_display_name_as_the_label_most_recent_first()
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'data' => [
                ['id' => 'claude-opus-5', 'display_name' => 'Claude Opus 5', 'type' => 'model'],
                ['id' => 'claude-sonnet-4-5', 'display_name' => 'Claude Sonnet 4.5', 'type' => 'model'],
            ],
        ], 200)]);

        $credential = AiProviderCredential::factory()->make(['provider' => 'anthropic']);

        $result = (new HttpAiProviderVerifier)->verify($credential);

        $this->assertSame([
            ['id' => 'claude-opus-5', 'label' => 'Claude Opus 5'],
            ['id' => 'claude-sonnet-4-5', 'label' => 'Claude Sonnet 4.5'],
        ], $result->models);
    }

    public function test_openai_models_are_parsed_with_id_as_the_label_sorted_newest_first()
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'object' => 'list',
            'data' => [
                ['id' => 'gpt-4o', 'object' => 'model', 'created' => 1000],
                ['id' => 'gpt-5.4', 'object' => 'model', 'created' => 2000],
            ],
        ], 200)]);

        $credential = AiProviderCredential::factory()->openai()->make();

        $result = (new HttpAiProviderVerifier)->verify($credential);

        $this->assertSame([
            ['id' => 'gpt-5.4', 'label' => 'gpt-5.4'],
            ['id' => 'gpt-4o', 'label' => 'gpt-4o'],
        ], $result->models);
    }

    public function test_no_data_field_results_in_an_empty_model_list_not_a_failure()
    {
        Http::fake(['api.anthropic.com/*' => Http::response([], 200)]);

        $credential = AiProviderCredential::factory()->make(['provider' => 'anthropic']);

        $result = (new HttpAiProviderVerifier)->verify($credential);

        $this->assertTrue($result->successful);
        $this->assertSame([], $result->models);
    }

    public function test_a_401_is_reported_as_an_invalid_key()
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'nope']], 401)]);

        $credential = AiProviderCredential::factory()->make(['provider' => 'anthropic']);

        $result = (new HttpAiProviderVerifier)->verify($credential);

        $this->assertFalse($result->successful);
        $this->assertSame('The provider rejected this key as invalid.', $result->error);
    }

    public function test_a_provider_error_message_is_surfaced_when_present()
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'rate limited, try later']], 429)]);

        $credential = AiProviderCredential::factory()->make(['provider' => 'anthropic']);

        $result = (new HttpAiProviderVerifier)->verify($credential);

        $this->assertFalse($result->successful);
        $this->assertSame('rate limited, try later', $result->error);
    }

    public function test_a_connection_failure_is_reported_without_leaking_the_exception()
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $credential = AiProviderCredential::factory()->make(['provider' => 'anthropic']);

        $result = (new HttpAiProviderVerifier)->verify($credential);

        $this->assertFalse($result->successful);
        $this->assertSame('Could not reach the provider. Check the base URL and your network.', $result->error);
    }
}
