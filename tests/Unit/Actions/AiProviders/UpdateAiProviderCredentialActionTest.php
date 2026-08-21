<?php

namespace Tests\Unit\Actions\AiProviders;

use App\Actions\AiProviders\UpdateAiProviderCredentialAction;
use App\Data\AiProviders\UpdateAiProviderCredentialData;
use App\Models\AiProviderCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateAiProviderCredentialActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_label_and_base_url()
    {
        $credential = AiProviderCredential::factory()->create([
            'label' => 'Old',
            'base_url' => null,
        ]);

        (new UpdateAiProviderCredentialAction)->handle($credential, new UpdateAiProviderCredentialData(
            label: 'New',
            baseUrl: 'https://example.com',
            apiKey: null,
        ));

        $credential->refresh();
        $this->assertSame('New', $credential->label);
        $this->assertSame('https://example.com', $credential->base_url);
    }

    public function test_a_null_api_key_leaves_the_stored_key_untouched()
    {
        $credential = AiProviderCredential::factory()->create(['api_key' => 'sk-original']);

        (new UpdateAiProviderCredentialAction)->handle($credential, new UpdateAiProviderCredentialData(
            label: $credential->label,
            baseUrl: $credential->base_url,
            apiKey: null,
        ));

        $this->assertSame('sk-original', $credential->fresh()->api_key);
    }

    public function test_a_given_api_key_replaces_the_stored_key_and_clears_verification()
    {
        $credential = AiProviderCredential::factory()->create([
            'api_key' => 'sk-original',
            'verified_at' => now(),
        ]);

        (new UpdateAiProviderCredentialAction)->handle($credential, new UpdateAiProviderCredentialData(
            label: $credential->label,
            baseUrl: $credential->base_url,
            apiKey: 'sk-rotated',
        ));

        $fresh = $credential->fresh();
        $this->assertSame('sk-rotated', $fresh->api_key);
        $this->assertNull($fresh->verified_at);
    }
}
