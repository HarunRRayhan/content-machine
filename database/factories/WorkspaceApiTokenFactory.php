<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkspaceApiToken>
 */
class WorkspaceApiTokenFactory extends Factory
{
    protected $model = WorkspaceApiToken::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by_user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            // Unique per row: token_hash has a global unique index. Tests
            // that need a known plaintext override token_hash explicitly.
            'token_hash' => WorkspaceApiToken::hash(Str::random(48)),
            'abilities' => WorkspaceApiToken::ABILITIES,
        ];
    }
}
