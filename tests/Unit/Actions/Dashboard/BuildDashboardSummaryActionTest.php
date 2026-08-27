<?php

namespace Tests\Unit\Actions\Dashboard;

use App\Actions\Dashboard\BuildDashboardSummaryAction;
use App\Models\Idea;
use App\Models\Post;
use App\Models\ScratchpadEntry;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Calendar\CollectCalendarOccurrences;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildDashboardSummaryActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function action(): BuildDashboardSummaryAction
    {
        return new BuildDashboardSummaryAction(new CollectCalendarOccurrences);
    }

    public function test_empty_workspace_returns_zero_counts(): void
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        $summary = $this->action()->handle($workspace)->toArray();

        $this->assertSame(['untriaged' => 0, 'total' => 0], $summary['scratchpad']);
        $this->assertSame(0, $summary['videos']['total']);
        $this->assertSame(0, $summary['posts']['draft']);
        $this->assertSame([], $summary['upcoming']);
        $this->assertSame('Asia/Dhaka', $summary['timezone']);
    }

    public function test_it_counts_only_the_current_workspace(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-27 10:00:00', 'Asia/Dhaka'));

        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);
        $other = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        ScratchpadEntry::factory()->for($workspace)->count(2)->create();
        ScratchpadEntry::factory()->for($workspace)->triaged()->create();
        ScratchpadEntry::factory()->for($other)->create();

        Idea::factory()->for($workspace)->create(['kind' => 'video', 'status' => 'open']);
        Idea::factory()->for($workspace)->create(['kind' => 'post', 'status' => 'open']);
        Idea::factory()->for($other)->create(['kind' => 'post', 'status' => 'open']);

        Video::factory()->for($workspace)->create(['status' => 'ready']);
        Video::factory()->for($workspace)->create(['status' => 'recorded']);
        Video::factory()->for($workspace)->create(['status' => 'posted']);
        Video::factory()->for($other)->create(['status' => 'posted']);

        Post::factory()->for($workspace)->create(['status' => 'draft']);
        Post::factory()->for($workspace)->create(['status' => 'ready']);
        Post::factory()->for($workspace)->create(['status' => 'posted']);
        Post::factory()->for($other)->create(['status' => 'draft']);

        $summary = $this->action()->handle($workspace)->toArray();

        $this->assertSame(['untriaged' => 2, 'total' => 3], $summary['scratchpad']);
        $this->assertSame(1, $summary['videos']['ideation']);
        $this->assertSame(1, $summary['videos']['ready']);
        $this->assertSame(1, $summary['videos']['recorded']);
        $this->assertSame(1, $summary['videos']['posted']);
        $this->assertSame(3, $summary['videos']['total']);
        $this->assertSame(1, $summary['posts']['ideation']);
        $this->assertSame(2, $summary['posts']['draft']);
        $this->assertSame(1, $summary['posts']['posted']);
    }

    public function test_upcoming_lists_scheduled_items_in_the_next_two_weeks(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-27 10:00:00', 'Asia/Dhaka'));

        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);

        $soon = Post::factory()->for($workspace)->create([
            'title' => 'Morning post',
            'human_id' => 'P-50',
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    ['scheduled_at' => '2026-08-28T09:00:00+06:00'],
                ],
            ],
        ]);

        Video::factory()->for($workspace)->create([
            'title' => 'Too far out',
            'human_id' => 'BV-99',
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    ['scheduled_at' => '2026-09-20T09:00:00+06:00'],
                ],
            ],
        ]);

        $summary = $this->action()->handle($workspace)->toArray();

        $this->assertCount(1, $summary['upcoming']);
        $this->assertSame('P-50', $summary['upcoming'][0]['human_id']);
        $this->assertSame('scheduled', $summary['upcoming'][0]['state']);
        $this->assertSame('/posts/P-50', $summary['upcoming'][0]['href']);
        $this->assertSame($soon->id, $summary['upcoming'][0]['id']);
        $this->assertSame(1, $summary['posts']['scheduled']);
    }
}
