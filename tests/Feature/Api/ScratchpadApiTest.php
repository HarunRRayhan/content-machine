<?php

namespace Tests\Feature\Api;

use App\Actions\ApiTokens\CreateWorkspaceApiTokenAction;
use App\Data\ApiTokens\CreateWorkspaceApiTokenData;
use App\Jobs\ResolveScratchpadLinkJob;
use App\Models\ContentVersion;
use App\Models\Idea;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use App\Models\StatusTransition;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScratchpadApiTest extends TestCase
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

    private function acting(): self
    {
        return $this->withToken($this->token);
    }

    public function test_index_defaults_to_new_entries_newest_first()
    {
        $old = ScratchpadEntry::factory()->for($this->workspace)->create([
            'kind' => 'text', 'body' => 'older new one', 'captured_at' => now()->subHour(),
        ]);
        $new = ScratchpadEntry::factory()->for($this->workspace)->create([
            'kind' => 'text', 'body' => 'newest new one',
        ]);
        ScratchpadEntry::factory()->for($this->workspace)->create(['status' => 'triaged']);
        ScratchpadEntry::factory()->for($this->workspace)->create(['status' => 'dropped']);

        $this->acting()->getJson('/api/v1/scratchpad')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.public_id', $new->public_id)
            ->assertJsonPath('data.1.public_id', $old->public_id);

        $this->acting()->getJson('/api/v1/scratchpad?status=all')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_index_filters_by_kind()
    {
        ScratchpadEntry::factory()->for($this->workspace)->create(['kind' => 'text']);
        ScratchpadEntry::factory()->for($this->workspace)->create(['kind' => 'link']);

        $this->acting()->getJson('/api/v1/scratchpad?kind=link')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_show_returns_the_full_entry()
    {
        $entry = ScratchpadEntry::factory()->for($this->workspace)->create([
            'kind' => 'text', 'body' => 'hello from the phone',
        ]);

        $this->acting()->getJson("/api/v1/scratchpad/{$entry->public_id}")
            ->assertOk()
            ->assertJsonPath('data.public_id', $entry->public_id)
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.source', 'web')
            ->assertJsonPath('data.idea', null);
    }

    public function test_capture_text_creates_an_api_sourced_entry_attributed_to_the_token()
    {
        $response = $this->acting()->postJson('/api/v1/scratchpad/text', [
            'body' => 'captured from personal-content',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.source', 'api')
            ->assertJsonPath('data.body', 'captured from personal-content');

        $entry = ScratchpadEntry::query()->sole();

        $transition = StatusTransition::query()
            ->where('subject_type', $entry::class)
            ->where('subject_id', $entry->id)
            ->firstOrFail();

        $this->assertSame('token', $transition->actor_type);
        $this->assertSame('test client', $transition->token_name);
    }

    public function test_capture_text_validates_body_presence()
    {
        $this->acting()->postJson('/api/v1/scratchpad/text', [])->assertUnprocessable();
    }

    public function test_capture_link_queues_resolution()
    {
        Queue::fake();

        $this->acting()->postJson('/api/v1/scratchpad/link', [
            'url' => 'https://example.com/reel/123',
        ])->assertCreated()->assertJsonPath('data.kind', 'link');

        Queue::assertPushed(ResolveScratchpadLinkJob::class);
    }

    public function test_capture_photo_stores_the_file_and_streams_it_back()
    {
        Storage::fake('scratchpad');

        $response = $this->acting()->post('/api/v1/scratchpad/photo', [
            'photo' => UploadedFile::fake()->image('shot.png', 10, 10),
            'caption' => 'a whiteboard',
        ]);

        $response->assertCreated()->assertJsonPath('data.kind', 'photo');

        /** @var MediaAsset $asset */
        $asset = MediaAsset::query()->sole();
        Storage::disk('scratchpad')->assertExists($asset->path);

        $entryPublicId = $response->json('data.public_id');

        $this->acting()->get("/api/v1/scratchpad/{$entryPublicId}/media/{$asset->id}")
            ->assertOk();
    }

    public function test_media_of_another_workspace_is_not_found()
    {
        Storage::fake('scratchpad');

        $foreignAsset = MediaAsset::factory()->create([
            'disk' => 'scratchpad',
            'mime' => 'image/png',
        ]);
        Storage::disk('scratchpad')->put($foreignAsset->path, 'bytes');

        $entry = ScratchpadEntry::factory()->for($this->workspace)->create(['kind' => 'text']);

        $this->acting()->get("/api/v1/scratchpad/{$entry->public_id}/media/{$foreignAsset->id}")
            ->assertNotFound();
    }

    public function test_update_changes_only_the_fields_sent_and_records_versions()
    {
        $entry = ScratchpadEntry::factory()->for($this->workspace)->create([
            'kind' => 'voice',
            'title' => null,
            'language' => 'bn',
            'body' => 'original transcript',
        ]);

        $this->acting()->patchJson("/api/v1/scratchpad/{$entry->public_id}", [
            'body' => 'corrected transcript',
        ])->assertOk()->assertJsonPath('data.body', 'corrected transcript');

        $version = ContentVersion::query()->where('field', 'body')->sole();
        $this->assertSame('original transcript', $version->old_value);
        $this->assertSame('corrected transcript', $version->new_value);

        // language untouched: no version row for it.
        $this->assertSame(1, ContentVersion::query()->count());
        $this->assertSame('bn', $entry->fresh()->language);
    }

    public function test_update_records_the_token_actor_on_field_changes()
    {
        $entry = ScratchpadEntry::factory()->for($this->workspace)->create(['kind' => 'text']);

        $this->acting()->patchJson("/api/v1/scratchpad/{$entry->public_id}", [
            'title' => 'A better title',
        ])->assertOk();

        $version = ContentVersion::query()->sole();

        $this->assertSame('token', $version->actor_type);
        $this->assertSame('test client', $version->token_name);
    }

    public function test_update_rejects_an_empty_patch()
    {
        $entry = ScratchpadEntry::factory()->for($this->workspace)->create(['kind' => 'text']);

        $this->acting()->patchJson("/api/v1/scratchpad/{$entry->public_id}", [])
            ->assertUnprocessable();
    }

    public function test_update_refuses_a_dropped_entry()
    {
        $entry = ScratchpadEntry::factory()->for($this->workspace)->create([
            'kind' => 'text',
            'status' => 'dropped',
            'drop_reason' => 'done with it',
        ]);

        $this->acting()->patchJson("/api/v1/scratchpad/{$entry->public_id}", [
            'title' => 'zombie',
        ])->assertStatus(409);
    }

    public function test_destroy_deletes_a_new_entry_but_refuses_a_triaged_one()
    {
        $fresh = ScratchpadEntry::factory()->for($this->workspace)->create(['kind' => 'text']);
        $triaged = ScratchpadEntry::factory()->for($this->workspace)->create(['status' => 'triaged']);
        Idea::factory()->for($this->workspace)->create([
            'kind' => 'post',
            'scratchpad_entry_id' => $triaged->id,
        ]);

        $this->acting()->deleteJson("/api/v1/scratchpad/{$fresh->public_id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);
        $this->assertModelMissing($fresh);

        $this->acting()->deleteJson("/api/v1/scratchpad/{$triaged->public_id}")
            ->assertStatus(409);
        $this->assertModelExists($triaged);
    }

    public function test_triage_files_a_post_idea_and_flips_the_entry()
    {
        $entry = ScratchpadEntry::factory()->for($this->workspace)->create([
            'kind' => 'text',
            'body' => 'an idea about sync jobs',
        ]);

        $this->acting()->postJson("/api/v1/scratchpad/{$entry->public_id}/triage", [
            'target' => 'post_idea',
            'title' => 'Sync job cost spike',
            'score' => 850,
            'trend' => 'evergreen',
            'rationale' => 'money + relatable',
        ])->assertOk()
            ->assertJsonPath('data.status', 'triaged')
            ->assertJsonPath('data.idea.human_id', 'PI-1');

        $this->assertSame('triaged', $entry->fresh()->status);

        $idea = Idea::query()->sole();
        $this->assertSame('post', $idea->kind);
        $this->assertSame(850, $idea->score);
        $this->assertSame($entry->id, $idea->scratchpad_entry_id);
    }
}
