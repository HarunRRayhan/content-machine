<?php

namespace Database\Factories;

use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProviderCredentialModel>
 */
class AiProviderCredentialModelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_provider_credential_id' => AiProviderCredential::factory(),
            'model' => 'claude-sonnet-4-5',
            'purpose' => 'default',
            'priority' => 0,
        ];
    }

    public function vision(): static
    {
        return $this->state(fn () => ['purpose' => 'vision']);
    }
}
