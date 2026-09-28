<?php

namespace Tests\Unit\Actions\Videos;

use App\Actions\Videos\SaveVideoSeriesAction;
use App\Data\Videos\SaveVideoSeriesData;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaveVideoSeriesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_and_reorders_a_series_atomically(): void
    {
        $workspace = Workspace::factory()->create();
        $first = Video::factory()->for($workspace)->create(['human_id' => 'V-85']);
        $second = Video::factory()->for($workspace)->create(['human_id' => 'V-86']);
        $action = new SaveVideoSeriesAction;

        $series = $action->handle($workspace, new SaveVideoSeriesData('vpn', 'VPN', ['V-85', 'V-86']));
        $this->assertSame(['V-85', 'V-86'], $series->videos->pluck('human_id')->all());
        $this->assertSame([1, 2], $series->videos->pluck('series_part')->all());

        $series = $action->handle($workspace, new SaveVideoSeriesData('vpn', 'VPN', ['V-86', 'V-85']));
        $this->assertSame(['V-86', 'V-85'], $series->videos->pluck('human_id')->all());
        $this->assertSame($series->id, $first->fresh()->series_id);
        $this->assertSame(2, $first->fresh()->series_part);
        $this->assertSame(1, $second->fresh()->series_part);
        $this->assertDatabaseCount('video_series', 1);
    }

    public function test_rejects_cross_workspace_members_without_partial_change(): void
    {
        $workspace = Workspace::factory()->create();
        $mine = Video::factory()->for($workspace)->create(['human_id' => 'V-85']);
        $other = Video::factory()->for(Workspace::factory()->create())->create(['human_id' => 'V-86']);

        try {
            (new SaveVideoSeriesAction)->handle($workspace, new SaveVideoSeriesData('vpn', 'VPN', ['V-85', 'V-86']));
            $this->fail('Expected validation error.');
        } catch (ValidationException) {
            $this->assertNull($mine->fresh()->series_id);
            $this->assertNull($other->fresh()->series_id);
            $this->assertDatabaseCount('video_series', 0);
        }
    }

    public function test_rejects_a_video_already_in_another_series(): void
    {
        $workspace = Workspace::factory()->create();
        Video::factory()->for($workspace)->create(['human_id' => 'V-85']);
        (new SaveVideoSeriesAction)->handle($workspace, new SaveVideoSeriesData('vpn', 'VPN', ['V-85']));

        $this->expectException(ValidationException::class);
        (new SaveVideoSeriesAction)->handle($workspace, new SaveVideoSeriesData('other', 'Other', ['V-85']));
    }
}
