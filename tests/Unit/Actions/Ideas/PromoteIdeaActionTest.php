<?php

namespace Tests\Unit\Actions\Ideas;

use App\Actions\Ideas\PromoteIdeaAction;
use App\Actions\Ids\ReserveContentIdAction;
use App\Models\ContentId;
use App\Models\Idea;
use App\Models\Post;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PromoteIdeaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_promoting_a_post_idea_creates_a_draft_post()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $idea = Idea::factory()->create([
            'kind' => 'post',
            'status' => 'open',
            'title' => 'A great idea',
            'body' => 'The full pitch.',
        ]);

        $entity = (new PromoteIdeaAction(new ReserveContentIdAction))->handle($idea);

        $this->assertInstanceOf(Post::class, $entity);
        $this->assertSame($idea->workspace_id, $entity->workspace_id);
        $this->assertSame($idea->id, $entity->idea_id);
        $this->assertSame('A great idea', $entity->title);
        $this->assertSame('The full pitch.', $entity->body);
        $this->assertSame('draft', $entity->status);
        $this->assertSame($user->id, $entity->created_by_user_id);
        $this->assertStringStartsWith('P-', $entity->human_id);

        $this->assertSame('promoted', $idea->refresh()->status);
    }

    public function test_promoting_a_video_idea_creates_a_draft_video()
    {
        $idea = Idea::factory()->create(['kind' => 'video', 'status' => 'open']);

        $entity = (new PromoteIdeaAction(new ReserveContentIdAction))->handle($idea);

        $this->assertInstanceOf(Video::class, $entity);
        $this->assertStringStartsWith('V-', $entity->human_id);
    }

    public function test_it_claims_the_content_id_reservation()
    {
        $idea = Idea::factory()->create(['kind' => 'post', 'status' => 'open']);

        $entity = (new PromoteIdeaAction(new ReserveContentIdAction))->handle($idea);

        $contentId = ContentId::where('human_id', $entity->human_id)->sole();
        $this->assertSame($entity->getMorphClass(), $contentId->entity_type);
        $this->assertSame($entity->id, $contentId->entity_id);
    }

    public function test_it_records_a_status_transition()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $idea = Idea::factory()->create(['kind' => 'post', 'status' => 'open']);

        (new PromoteIdeaAction(new ReserveContentIdAction))->handle($idea);

        $this->assertDatabaseHas('status_transitions', [
            'subject_type' => $idea->getMorphClass(),
            'subject_id' => $idea->id,
            'from' => 'open',
            'to' => 'promoted',
            'actor_type' => 'user',
            'actor_id' => $user->id,
        ]);
    }

    public function test_an_already_promoted_idea_cannot_be_promoted_again()
    {
        $idea = Idea::factory()->promoted()->create(['kind' => 'post']);

        $this->expectException(RuntimeException::class);

        (new PromoteIdeaAction(new ReserveContentIdAction))->handle($idea);
    }

    public function test_a_dropped_idea_cannot_be_promoted()
    {
        $idea = Idea::factory()->dropped()->create(['kind' => 'post']);

        $this->expectException(RuntimeException::class);

        (new PromoteIdeaAction(new ReserveContentIdAction))->handle($idea);
    }

    public function test_a_feature_idea_cannot_be_promoted()
    {
        $idea = Idea::factory()->create(['kind' => 'feature', 'status' => 'open']);

        $this->expectException(RuntimeException::class);

        (new PromoteIdeaAction(new ReserveContentIdAction))->handle($idea);
    }

    public function test_successive_promotions_in_the_same_workspace_get_sequential_numbers()
    {
        $idea1 = Idea::factory()->create(['kind' => 'post', 'status' => 'open']);
        $idea2 = Idea::factory()->for($idea1->workspace)->create(['kind' => 'post', 'status' => 'open']);

        $action = new PromoteIdeaAction(new ReserveContentIdAction);
        $first = $action->handle($idea1);
        $second = $action->handle($idea2);

        $this->assertSame($first->number + 1, $second->number);
    }
}
