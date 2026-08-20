<?php

namespace Tests\Unit\Actions\Scratchpad;

use App\Actions\Ids\ReserveContentIdAction;
use App\Actions\Scratchpad\TriageScratchpadEntryAction;
use App\Data\Scratchpad\TriageScratchpadEntryData;
use App\Models\ContentId;
use App\Models\Idea;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TriageScratchpadEntryActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(): TriageScratchpadEntryAction
    {
        return new TriageScratchpadEntryAction(new ReserveContentIdAction);
    }

    public function test_filing_as_a_post_idea_creates_an_idea_and_claims_the_content_id()
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $entry = ScratchpadEntry::factory()->for($workspace)->create(['body' => 'A neat idea.']);

        $data = new TriageScratchpadEntryData(
            target: 'post_idea',
            title: 'A great post',
            score: 750,
            trend: 'evergreen',
            rationale: 'Fits the lane well.',
        );

        $triaged = $this->action()->handle($entry, $user, $data);

        $idea = Idea::sole();
        $this->assertSame('post', $idea->kind);
        $this->assertSame(1, $idea->number);
        $this->assertSame('PI-1', $idea->human_id);
        $this->assertSame('A great post', $idea->title);
        $this->assertSame('a-great-post', $idea->slug);
        $this->assertSame(750, $idea->score);
        $this->assertSame('evergreen', $idea->trend);
        $this->assertSame('Fits the lane well.', $idea->rationale);
        $this->assertSame('A neat idea.', $idea->body);
        $this->assertSame('open', $idea->status);
        $this->assertSame($entry->id, $idea->scratchpad_entry_id);
        $this->assertSame($user->id, $idea->created_by_user_id);
        $this->assertSame($workspace->id, $idea->workspace_id);

        $contentId = ContentId::sole();
        $this->assertSame('PI-1', $contentId->human_id);
        $this->assertSame(Idea::class, $contentId->entity_type);
        $this->assertSame($idea->id, $contentId->entity_id);

        $this->assertSame('triaged', $triaged->status);
        $this->assertNotNull($triaged->triaged_at);
        $this->assertSame($user->id, $triaged->triaged_by_user_id);
    }

    public function test_filing_as_a_video_idea_creates_an_idea_with_kind_video()
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        $data = new TriageScratchpadEntryData(target: 'video_idea', title: 'A great video');

        $this->action()->handle($entry, $user, $data);

        $idea = Idea::sole();
        $this->assertSame('video', $idea->kind);
        $this->assertSame('VI-1', $idea->human_id);

        $contentId = ContentId::sole();
        $this->assertSame('video_idea', $contentId->kind);
    }

    public function test_two_ideas_from_the_same_title_in_the_same_workspace_get_distinct_slugs()
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        $firstEntry = ScratchpadEntry::factory()->for($workspace)->create();
        $secondEntry = ScratchpadEntry::factory()->for($workspace)->create();

        $data = new TriageScratchpadEntryData(target: 'post_idea', title: 'Same title');

        $this->action()->handle($firstEntry, $user, $data);
        $this->action()->handle($secondEntry, $user, $data);

        $slugs = Idea::orderBy('id')->pluck('slug')->all();
        $this->assertSame(['same-title', 'same-title-2'], $slugs);
    }

    public function test_dropping_sets_status_and_reason_without_creating_an_idea()
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        $data = new TriageScratchpadEntryData(target: 'drop', dropReason: 'Not relevant.');

        $dropped = $this->action()->handle($entry, $user, $data);

        $this->assertSame('dropped', $dropped->status);
        $this->assertSame('Not relevant.', $dropped->drop_reason);
        $this->assertNotNull($dropped->triaged_at);
        $this->assertSame($user->id, $dropped->triaged_by_user_id);
        $this->assertSame(0, Idea::count());
    }

    public function test_an_already_triaged_entry_cannot_be_re_triaged()
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $entry = ScratchpadEntry::factory()->for($workspace)->triaged()->create();

        $this->expectException(RuntimeException::class);

        $this->action()->handle(
            $entry,
            $user,
            new TriageScratchpadEntryData(target: 'post_idea', title: 'Too late'),
        );
    }

    public function test_an_already_dropped_entry_cannot_be_triaged()
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $entry = ScratchpadEntry::factory()->for($workspace)->dropped()->create();

        $this->expectException(RuntimeException::class);

        $this->action()->handle(
            $entry,
            $user,
            new TriageScratchpadEntryData(target: 'drop', dropReason: 'Again?'),
        );
    }

    public function test_it_records_a_status_transition_when_filing_as_an_idea()
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        $this->action()->handle(
            $entry,
            $user,
            new TriageScratchpadEntryData(target: 'post_idea', title: 'Title'),
        );

        $this->assertDatabaseHas('status_transitions', [
            'subject_type' => $entry->getMorphClass(),
            'subject_id' => $entry->id,
            'from' => 'new',
            'to' => 'triaged',
            'actor_type' => 'user',
            'actor_id' => $user->id,
        ]);
    }

    public function test_it_records_a_status_transition_when_dropping()
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        $this->action()->handle(
            $entry,
            $user,
            new TriageScratchpadEntryData(target: 'drop', dropReason: 'Nope.'),
        );

        $this->assertDatabaseHas('status_transitions', [
            'subject_type' => $entry->getMorphClass(),
            'subject_id' => $entry->id,
            'from' => 'new',
            'to' => 'dropped',
            'reason' => 'Nope.',
            'actor_type' => 'user',
            'actor_id' => $user->id,
        ]);
    }
}
