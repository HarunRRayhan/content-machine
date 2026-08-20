<?php

namespace Tests\Unit\Actions\Posts;

use App\Actions\Posts\UpdatePostAction;
use App\Data\Posts\UpdatePostData;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdatePostActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_the_editable_fields()
    {
        $post = Post::factory()->create([
            'title' => 'Old title',
            'body' => 'Old body.',
        ]);

        $updated = (new UpdatePostAction)->handle($post, new UpdatePostData(
            title: 'New title',
            body: 'New body.',
        ));

        $this->assertSame('New title', $updated->title);
        $this->assertSame('New body.', $updated->body);

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'title' => 'New title',
            'body' => 'New body.',
        ]);
    }

    public function test_it_does_not_touch_number_human_id_status_or_idea_id()
    {
        $post = Post::factory()->create([
            'number' => 4,
            'human_id' => 'P-4',
            'status' => 'draft',
            'idea_id' => null,
        ]);

        (new UpdatePostAction)->handle($post, new UpdatePostData(title: 'Renamed', body: null));

        $post->refresh();

        $this->assertSame(4, $post->number);
        $this->assertSame('P-4', $post->human_id);
        $this->assertSame('draft', $post->status);
        $this->assertNull($post->idea_id);
    }
}
