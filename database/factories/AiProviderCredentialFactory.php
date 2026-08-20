<?php

namespace Database\Factories;

use App\Models\AiProviderCredential;
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
            'model' => 'claude-sonnet-4-5',
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
            'model' => 'gpt-4o',
            'api_key' => 'sk-'.fake()->uuid(),
        ]);
    }
}
