<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class VersionApiTest extends TestCase
{
    public function test_version_is_public_and_reports_the_deployed_commit(): void
    {
        config(['app.deploy.commit' => '0b5c436ebcf64a5cd4c8289970a5648642624246']);

        $this->getJson('/api/version')
            ->assertOk()
            ->assertJsonPath('commit', '0b5c436ebcf64a5cd4c8289970a5648642624246')
            ->assertJsonPath('short', '0b5c436')
            ->assertJsonStructure(['branch', 'deployment_id', 'environment', 'service', 'php', 'laravel']);
    }
}
