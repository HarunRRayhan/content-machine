<?php

namespace Database\Factories;

use App\Models\AiProviderCredential;
use App\Models\AiProviderCredentialModel;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProviderCredential>
 */
class AiProviderCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'label' => fake()->words(2, true),
            'provider' => 'anthropic',
            'base_url' => null,
            'api_key' => 'sk-ant-'.fake()->uuid(),
            'priority' => 0,
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }

    public function openai(): static
    {
        return $this->state(fn () => [
            'provider' => 'openai',
            'api_key' => 'sk-'.fake()->uuid(),
        ]);
    }

    /**
     * Convenience for tests that need a credential already resolvable in
     * the fallback chain: attaches one AiProviderCredentialModel row once
     * the credential itself is persisted. Only fires on ->create(), not
     * ->make(), same as any relationship a factory sets up this way.
     */
    public function withModel(string $model = 'claude-sonnet-4-5', string $purpose = 'default'): static
    {
        return $this->afterCreating(function (AiProviderCredential $credential) use ($model, $purpose) {
            AiProviderCredentialModel::factory()->create([
                'ai_provider_credential_id' => $credential->id,
                'model' => $model,
                'purpose' => $purpose,
            ]);
        });
    }
}
