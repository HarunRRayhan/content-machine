<?php

namespace Tests\Unit\Models;

use App\Models\AiProviderCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiProviderCredentialTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The first encrypted column in this app: proves api_key is genuinely
     * ciphertext at rest, not just masked in the UI. A raw query bypasses
     * Eloquent's cast, so this reads exactly what's on disk.
     */
    public function test_the_api_key_is_encrypted_at_rest()
    {
        $credential = AiProviderCredential::factory()->create(['api_key' => 'sk-ant-plaintext-value']);

        $raw = DB::table('ai_provider_credentials')->where('id', $credential->id)->value('api_key');

        $this->assertNotSame('sk-ant-plaintext-value', $raw);
        $this->assertStringNotContainsString('sk-ant-plaintext-value', $raw);
        $this->assertSame('sk-ant-plaintext-value', $credential->fresh()->api_key);
    }
}
