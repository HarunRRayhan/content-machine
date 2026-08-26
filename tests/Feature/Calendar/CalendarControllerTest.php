<?php

namespace Tests\Feature\Calendar;

use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsWorkspaceMember(): array
    {
        $workspace = Workspace::factory()->create(['timezone' => 'Asia/Dhaka']);
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        return [$user, $workspace];
    }

    public function test_guests_cannot_view_the_calendar(): void
    {
        $this->get(route('calendar.index'))->assertRedirect(route('login'));
    }

    public function test_the_calendar_page_lists_scheduled_posts_for_the_month(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        Post::factory()->for($workspace)->create([
            'title' => 'On the calendar',
            'human_id' => 'P-51',
            'status' => 'scheduled',
            'postsyncer' => [
                'groups' => [
                    ['scheduled_at' => '2026-08-26T21:18:00+06:00'],
                ],
            ],
        ]);

        $this->get(route('calendar.index', ['year' => 2026, 'month' => 8]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calendar/index')
                ->where('year', 2026)
                ->where('month', 8)
                ->where('timezone', 'Asia/Dhaka')
                ->has('events', 1)
                ->where('events.0.human_id', 'P-51')
                ->where('events.0.state', 'scheduled')
                ->where('events.0.date', '2026-08-26')
            );
    }
}
