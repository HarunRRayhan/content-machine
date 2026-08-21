<?php

namespace Tests\Unit\Actions\AiProviders;

use App\Actions\AiProviders\RemoveAiProviderCredentialModelAction;
use App\Models\AiProviderCredentialModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemoveAiProviderCredentialModelActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_the_entry()
    {
        $entry = AiProviderCredentialModel::factory()->create();

        (new RemoveAiProviderCredentialModelAction)->handle($entry);

        $this->assertDatabaseMissing('ai_provider_credential_models', ['id' => $entry->id]);
    }
}
