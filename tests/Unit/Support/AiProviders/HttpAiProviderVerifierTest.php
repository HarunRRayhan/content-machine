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
