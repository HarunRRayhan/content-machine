<?php

namespace Tests\Feature\Posts;

use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovePostControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_workspace_member_can_approve_a_pending_post(): void
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);
        $post = Post::factory()->create([
            'workspace_id' => $workspace->id,
            'approval_state' => 'pending',
        ]);

        $this->actingAs($user)
            ->post(route('posts.approve', $post))
            ->assertRedirect(route('posts.show', $post));

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'approval_state' => 'approved',
            'approved_by_user_id' => $user->id,
        ]);
    }
}
