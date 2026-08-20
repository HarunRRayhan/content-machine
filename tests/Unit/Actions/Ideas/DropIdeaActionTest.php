<?php

namespace Tests\Unit\Actions\Ideas;

use App\Actions\Ideas\DropIdeaAction;
use App\Data\Ideas\DropIdeaData;
use App\Models\Idea;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DropIdeaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_status_dropped_and_the_reason()
    {
        $idea = Idea::factory()->create(['status' => 'open']);

        $dropped = (new DropIdeaAction)->handle($idea, new DropIdeaData(dropReason: 'No longer relevant.'));

        $this->assertSame('dropped', $dropped->status);
        $this->assertSame('No longer relevant.', $dropped->drop_reason);
        $this->assertDatabaseHas('ideas', [
            'id' => $idea->id,
            'status' => 'dropped',
            'drop_reason' => 'No longer relevant.',
        ]);
    }

    public function test_it_records_a_status_transition()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $idea = Idea::factory()->create(['status' => 'open']);

        (new DropIdeaAction)->handle($idea, new DropIdeaData(dropReason: 'Stale.'));

        $this->assertDatabaseHas('status_transitions', [
            'subject_type' => $idea->getMorphClass(),
            'subject_id' => $idea->id,
            'from' => 'open',
            'to' => 'dropped',
            'reason' => 'Stale.',
            'actor_type' => 'user',
            'actor_id' => $user->id,
        ]);
    }

    public function test_an_already_dropped_idea_cannot_be_dropped_again()
    {
        $idea = Idea::factory()->dropped()->create();

        $this->expectException(RuntimeException::class);

        (new DropIdeaAction)->handle($idea, new DropIdeaData(dropReason: 'Again.'));
    }
}
