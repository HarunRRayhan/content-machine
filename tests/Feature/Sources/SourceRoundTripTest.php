<?php

namespace Tests\Feature\Sources;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Actions\Ideas\PromoteIdeaAction;
use App\Actions\Ids\ReserveContentIdAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Models\Idea;
use App\Models\Post;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->token = (new CreateWorkspaceApiTokenAction)->handle(
            $this->workspace,
            User::factory()->create(),
            new CreateWorkspaceApiTokenData('test client'),
        )['plaintext'];
    }

    /**
     * @return array<string, mixed>
     */
    private function source(): array
    {
        return [
            'source_links' => [['url' => 'https://example.com/a', 'label' => 'Original']],
            'source_text' => 'Raw pasted text.',
        ];
    }

    public function test_idea_source_round_trips_and_is_preserved_by_a_plain_update(): void
    {
        Idea::factory()->for($this->workspace)->create(['kind' => 'post', 'human_id' => 'PI-1']);

        $this->withToken($this->token)->patchJson('/api/v1/ideas/PI-1', ['title' => 'T'] + $this->source())
            ->assertOk()
            ->assertJsonPath('data.source_links.0.url', 'https://example.com/a')
            ->assertJsonPath('data.source_links.0.label', 'Original')
            ->assertJsonPath('data.source_text', 'Raw pasted text.');

        // An update that omits source keys must not wipe them.
        $this->withToken($this->token)->patchJson('/api/v1/ideas/PI-1', ['title' => 'T2'])
            ->assertOk()
            ->assertJsonPath('data.source_text', 'Raw pasted text.');

        $this->withToken($this->token)->patchJson('/api/v1/ideas/PI-1', ['title' => 'T2', 'source_links' => null])
            ->assertOk()
            ->assertJsonPath('data.source_links', []);
    }

    public function test_post_and_video_source_round_trip(): void
    {
        Post::factory()->for($this->workspace)->create(['human_id' => 'P-1']);
        Video::factory()->for($this->workspace)->create(['human_id' => 'V-1']);

        $this->withToken($this->token)->patchJson('/api/v1/posts/P-1', $this->source())
            ->assertOk()
            ->assertJsonPath('data.source_links.0.url', 'https://example.com/a')
            ->assertJsonPath('data.source_text', 'Raw pasted text.');

        $this->withToken($this->token)->patchJson('/api/v1/videos/V-1', $this->source())
            ->assertOk()
            ->assertJsonPath('data.source_links.0.url', 'https://example.com/a')
            ->assertJsonPath('data.source_text', 'Raw pasted text.');
    }

    public function test_invalid_source_links_are_rejected(): void
    {
        Idea::factory()->for($this->workspace)->create(['kind' => 'post', 'human_id' => 'PI-2']);

        $this->withToken($this->token)->patchJson('/api/v1/ideas/PI-2', [
            'title' => 'T',
            'source_links' => [['url' => 'not a url']],
        ])->assertUnprocessable();
    }

    public function test_promoting_an_idea_copies_its_source_to_the_post_and_video(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['post' => Post::class, 'video' => Video::class] as $kind => $model) {
            $idea = Idea::factory()->for($this->workspace)->create([
                'kind' => $kind,
                'status' => 'open',
                ...$this->source(),
            ]);

            $entity = (new PromoteIdeaAction(new ReserveContentIdAction))->handle($idea);

            $this->assertInstanceOf($model, $entity);
            $this->assertSame($this->source()['source_links'], $entity->fresh()->source_links);
            $this->assertSame('Raw pasted text.', $entity->fresh()->source_text);
        }
    }

    public function test_backfill_command_fills_idea_sources_from_the_scratchpad_entry(): void
    {
        $entry = ScratchpadEntry::factory()->for($this->workspace)->create([
            'title' => 'Thread',
            'body' => 'Body text',
            'meta' => ['url' => 'https://example.com/thread'],
        ]);
        $linked = Idea::factory()->for($this->workspace)->create(['scratchpad_entry_id' => $entry->id]);
        $untouched = Idea::factory()->for($this->workspace)->create(['source_text' => 'keep me']);

        $this->artisan('ideas:backfill-sources', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($linked->fresh()->source_text);

        $this->artisan('ideas:backfill-sources')->assertSuccessful();

        $this->assertSame('Body text', $linked->fresh()->source_text);
        $this->assertSame('https://example.com/thread', $linked->fresh()->source_links[0]['url']);
        $this->assertSame('keep me', $untouched->fresh()->source_text);
    }
}
