<?php

namespace Tests\Unit\Actions\Ideas;

use App\Actions\Ideas\UpdateIdeaAction;
use App\Data\Ideas\UpdateIdeaData;
use App\Models\Idea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateIdeaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_the_editable_fields()
    {
        $idea = Idea::factory()->create([
            'title' => 'Old title',
            'score' => 100,
            'trend' => 'seasonal',
            'rationale' => 'Old rationale.',
            'body' => 'Old body.',
        ]);

        $data = new UpdateIdeaData(
            title: 'New title',
            score: 900,
            trend: 'evergreen',
            rationale: 'New rationale.',
            body: 'New body.',
        );

        $updated = (new UpdateIdeaAction)->handle($idea, $data);

        $this->assertSame('New title', $updated->title);
        $this->assertSame(900, $updated->score);
        $this->assertSame('evergreen', $updated->trend);
        $this->assertSame('New rationale.', $updated->rationale);
        $this->assertSame('New body.', $updated->body);

        $this->assertDatabaseHas('ideas', [
            'id' => $idea->id,
            'title' => 'New title',
            'score' => 900,
        ]);
    }

    public function test_it_does_not_touch_kind_number_human_id_slug_or_status()
    {
        $idea = Idea::factory()->create([
            'kind' => 'video',
            'number' => 4,
            'human_id' => 'VI-4',
            'slug' => 'original-slug',
            'status' => 'open',
        ]);

        (new UpdateIdeaAction)->handle($idea, new UpdateIdeaData(
            title: 'Renamed',
            score: null,
            trend: null,
            rationale: null,
            body: null,
        ));

        $idea->refresh();

        $this->assertSame('video', $idea->kind);
        $this->assertSame(4, $idea->number);
        $this->assertSame('VI-4', $idea->human_id);
        $this->assertSame('original-slug', $idea->slug);
        $this->assertSame('open', $idea->status);
    }
}
