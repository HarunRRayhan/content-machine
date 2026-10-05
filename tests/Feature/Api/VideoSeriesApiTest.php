<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoSeriesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_saves_and_reads_ordered_series_with_workspace_token(): void
    {
        $workspace = Workspace::factory()->create();
        Video::factory()->for($workspace)->declaresSeries()->create(['human_id' => 'V-85']);
        Video::factory()->for($workspace)->declaresSeries()->create(['human_id' => 'V-86']);
        $token = (new CreateWorkspaceApiTokenAction)->handle(
            $workspace,
            null,
            new CreateWorkspaceApiTokenData('series test'),
        )['plaintext'];

        $this->withToken($token)->putJson('/api/v1/series/vpn', [
            'title' => 'VPN',
            'videos' => ['V-85', 'V-86'],
        ])->assertOk()->assertJsonPath('data.videos.0.part', 1)
            ->assertJsonPath('data.videos.1.human_id', 'V-86');

        $this->withToken($token)->getJson('/api/v1/series/vpn')
            ->assertOk()->assertJsonPath('data.videos.1.part', 2);

        $this->withToken($token)->getJson('/api/v1/series')
            ->assertOk()->assertJsonPath('data.0.slug', 'vpn')
            ->assertJsonPath('data.0.videos_count', 2);

        $this->withToken($token)->getJson('/api/v1/videos/V-85')
            ->assertOk()->assertJsonPath('data.series.slug', 'vpn')
            ->assertJsonPath('data.series.part', 1);
    }

    public function test_rejects_duplicate_video_ids(): void
    {
        $workspace = Workspace::factory()->create();
        Video::factory()->for($workspace)->create(['human_id' => 'V-85']);
        $token = (new CreateWorkspaceApiTokenAction)->handle(
            $workspace,
            null,
            new CreateWorkspaceApiTokenData('series test'),
        )['plaintext'];

        $this->withToken($token)->putJson('/api/v1/series/vpn', [
            'title' => 'VPN',
            'videos' => ['V-85', 'V-85'],
        ])->assertUnprocessable();
    }

    public function test_read_only_token_cannot_change_series(): void
    {
        $workspace = Workspace::factory()->create();
        Video::factory()->for($workspace)->create(['human_id' => 'V-85']);
        $token = (new CreateWorkspaceApiTokenAction)->handle(
            $workspace,
            null,
            new CreateWorkspaceApiTokenData('read only', ['videos:read']),
        )['plaintext'];

        $this->withToken($token)->putJson('/api/v1/series/vpn', [
            'title' => 'VPN',
            'videos' => ['V-85'],
        ])->assertForbidden();
    }
}
