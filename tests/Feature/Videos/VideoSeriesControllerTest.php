<?php

namespace Tests\Feature\Videos;

use App\Actions\Videos\SaveVideoSeriesAction;
use App\Data\Videos\SaveVideoSeriesData;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VideoSeriesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_series_pages_and_video_detail_show_ordered_parts(): void
    {
        $workspace = $this->actingAsWorkspaceMember();
        Video::factory()->for($workspace)->create(['human_id' => 'V-85', 'title' => 'VPN intro']);
        Video::factory()->for($workspace)->create(['human_id' => 'V-86', 'title' => 'VPN tunnel']);
        (new SaveVideoSeriesAction)->handle($workspace, new SaveVideoSeriesData('vpn', 'VPN', ['V-85', 'V-86']));

        $this->get('/series')->assertInertia(fn (Assert $page) => $page
            ->component('videos/series/index')
            ->has('series', 1)
            ->where('series.0.title', 'VPN')
            ->where('series.0.videos_count', 2)
            ->where('series.0.preview.0.part', 1)
            ->where('series.0.preview.0.title', 'VPN intro')
            ->where('series.0.preview.1.part', 2)
            ->where('series.0.preview.1.title', 'VPN tunnel'));

        $this->get('/series/vpn')->assertInertia(fn (Assert $page) => $page
            ->component('videos/series/show')
            ->where('series.videos.0.human_id', 'V-85')
            ->where('series.videos.1.part', 2));

        $this->get('/videos/V-86')->assertInertia(fn (Assert $page) => $page
            ->component('videos/show')
            ->where('video.series.slug', 'vpn')
            ->where('video.series.part', 2));
    }

    public function test_series_pages_hide_other_workspaces(): void
    {
        $mine = $this->actingAsWorkspaceMember();
        $other = Workspace::factory()->create();
        Video::factory()->for($other)->create(['human_id' => 'V-85']);
        (new SaveVideoSeriesAction)->handle($other, new SaveVideoSeriesData('vpn', 'VPN', ['V-85']));

        $this->get('/series')->assertInertia(fn (Assert $page) => $page
            ->component('videos/series/index')
            ->has('series', 0));
        $this->get('/series/vpn')->assertNotFound();
        $this->assertNotNull($mine);
    }

    private function actingAsWorkspaceMember(): Workspace
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);
        $this->actingAs($user);

        return $workspace;
    }
}
