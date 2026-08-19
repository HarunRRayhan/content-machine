<?php

namespace Tests\Unit\Actions\Ids;

use App\Actions\Ids\ReserveContentIdAction;
use App\Models\ContentId;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReserveContentIdActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reserves_the_first_number_as_one_and_builds_the_human_id()
    {
        $workspace = Workspace::factory()->create();

        $contentId = (new ReserveContentIdAction)->handle($workspace, 'post_idea');

        $this->assertSame(1, $contentId->number);
        $this->assertSame('PI-1', $contentId->human_id);
        $this->assertSame($workspace->id, $contentId->workspace_id);
        $this->assertSame('post_idea', $contentId->kind);
        $this->assertSame('web', $contentId->reserved_via);
        $this->assertNotNull($contentId->reserved_at);
    }

    public function test_fifty_sequential_reservations_are_strictly_increasing_and_gapless()
    {
        $workspace = Workspace::factory()->create();
        $action = new ReserveContentIdAction;

        $numbers = [];

        for ($i = 0; $i < 50; $i++) {
            $numbers[] = $action->handle($workspace, 'post_idea')->number;
        }

        $this->assertSame(range(1, 50), $numbers);
        $this->assertSame(50, ContentId::where('workspace_id', $workspace->id)->count());
    }

    public function test_two_different_workspaces_each_start_their_own_sequence_at_one()
    {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();
        $action = new ReserveContentIdAction;

        $first = $action->handle($workspaceA, 'post_idea');
        $second = $action->handle($workspaceA, 'post_idea');
        $other = $action->handle($workspaceB, 'post_idea');

        $this->assertSame(1, $first->number);
        $this->assertSame(2, $second->number);
        $this->assertSame(1, $other->number);
    }

    public function test_two_different_kinds_in_the_same_workspace_each_have_their_own_sequence()
    {
        $workspace = Workspace::factory()->create();
        $action = new ReserveContentIdAction;

        $postIdea = $action->handle($workspace, 'post_idea');
        $videoIdea = $action->handle($workspace, 'video_idea');

        $this->assertSame(1, $postIdea->number);
        $this->assertSame('PI-1', $postIdea->human_id);
        $this->assertSame(1, $videoIdea->number);
        $this->assertSame('VI-1', $videoIdea->human_id);
    }

    public function test_replaying_the_same_idempotency_key_returns_the_same_reservation_without_burning_a_new_number()
    {
        $workspace = Workspace::factory()->create();
        $action = new ReserveContentIdAction;

        $first = $action->handle($workspace, 'post_idea', 'idem-key-1');
        $second = $action->handle($workspace, 'post_idea', 'idem-key-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->number, $second->number);
        $this->assertSame(1, ContentId::where('workspace_id', $workspace->id)->count());

        // The sequence itself wasn't burned twice either: the next fresh
        // reservation continues right after the one idempotent reservation.
        $next = $action->handle($workspace, 'post_idea');
        $this->assertSame(2, $next->number);
    }

    public function test_a_different_idempotency_key_reserves_a_new_number()
    {
        $workspace = Workspace::factory()->create();
        $action = new ReserveContentIdAction;

        $first = $action->handle($workspace, 'post_idea', 'idem-key-a');
        $second = $action->handle($workspace, 'post_idea', 'idem-key-b');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, $first->number);
        $this->assertSame(2, $second->number);
    }

    public function test_it_records_the_authenticated_user_as_the_reserver()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $workspace = Workspace::factory()->create();
        $contentId = (new ReserveContentIdAction)->handle($workspace, 'post_idea');

        $this->assertSame($user->id, $contentId->reserved_by_user_id);
    }

    public function test_it_throws_for_an_unconfigured_kind()
    {
        $workspace = Workspace::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        (new ReserveContentIdAction)->handle($workspace, 'not_a_real_kind');
    }
}
