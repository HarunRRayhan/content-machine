<?php

namespace Tests\Feature\Scratchpad;

use App\Jobs\ResolveScratchpadLinkJob;
use App\Models\AiProviderCredential;
use App\Models\Attachment;
use App\Models\Idea;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AiProviders\AiCompletionClientContract;
use App\Support\AiProviders\AiCompletionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ScratchpadControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsWorkspaceMember(): array
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        return [$user, $workspace];
    }

    public function test_guests_cannot_view_the_scratchpad()
    {
        $this->get(route('dashboard.scratchpad.index'))->assertRedirect(route('login'));
    }

    public function test_index_only_lists_the_current_workspaces_entries()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $mine = ScratchpadEntry::factory()->for($workspace)->create(['body' => 'Mine']);

        $otherWorkspace = Workspace::factory()->create();
        ScratchpadEntry::factory()->for($otherWorkspace)->create(['body' => 'Not mine']);

        $this->get(route('dashboard.scratchpad.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('scratchpad/index')
                ->has('entries.data', 1)
                ->where('entries.data.0.id', $mine->id)
            );
    }

    public function test_store_creates_an_entry_and_redirects()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $response = $this->post(route('dashboard.scratchpad.store'), [
            'body' => 'A quick captured thought.',
        ]);

        $response->assertRedirect(route('dashboard.scratchpad.index'));

        $this->assertDatabaseHas('scratchpad_entries', [
            'workspace_id' => $workspace->id,
            'kind' => 'text',
            'source' => 'web',
            'status' => 'new',
            'body' => 'A quick captured thought.',
        ]);
    }

    public function test_store_records_a_status_transition_on_capture()
    {
        [$user] = $this->actingAsWorkspaceMember();

        $this->post(route('dashboard.scratchpad.store'), [
            'body' => 'A quick captured thought.',
        ]);

        $entry = ScratchpadEntry::sole();

        $this->assertDatabaseHas('status_transitions', [
            'subject_type' => $entry->getMorphClass(),
            'subject_id' => $entry->id,
            'from' => null,
            'to' => 'new',
            'actor_type' => 'user',
            'actor_id' => $user->id,
        ]);
    }

    public function test_store_validates_an_empty_body()
    {
        $this->actingAsWorkspaceMember();

        $response = $this->post(route('dashboard.scratchpad.store'), [
            'body' => '',
        ]);

        $response->assertSessionHasErrors(['body']);
        $this->assertDatabaseCount('scratchpad_entries', 0);
    }

    public function test_show_renders_an_entry_in_the_current_workspace()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $entry = ScratchpadEntry::factory()->for($workspace)->create(['body' => 'Hello there']);

        $this->get(route('dashboard.scratchpad.show', $entry))
            ->assertInertia(fn (Assert $page) => $page
                ->component('scratchpad/show')
                ->where('entry.id', $entry->id)
                ->where('entry.body', 'Hello there')
            );
    }

    public function test_show_404s_for_an_entry_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->for($otherWorkspace)->create();

        $this->get(route('dashboard.scratchpad.show', $entry))->assertNotFound();
    }

    public function test_triage_as_post_idea_files_an_idea_and_marks_the_entry_triaged()
    {
        [$user, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->create(['body' => 'Raw capture.']);

        $response = $this->post(route('dashboard.scratchpad.triage', $entry), [
            'target' => 'post_idea',
            'title' => 'A filed idea',
            'score' => 600,
            'trend' => 'seasonal',
            'rationale' => 'Timely.',
        ]);

        $response->assertRedirect(route('dashboard.scratchpad.show', $entry));

        $this->assertDatabaseHas('scratchpad_entries', [
            'id' => $entry->id,
            'status' => 'triaged',
            'triaged_by_user_id' => $user->id,
        ]);

        $idea = Idea::sole();
        $this->assertSame('post', $idea->kind);
        $this->assertSame('A filed idea', $idea->title);
        $this->assertSame($entry->id, $idea->scratchpad_entry_id);
    }

    public function test_triage_as_video_idea_files_a_video_idea()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        $this->post(route('dashboard.scratchpad.triage', $entry), [
            'target' => 'video_idea',
            'title' => 'A filed video idea',
        ])->assertRedirect(route('dashboard.scratchpad.show', $entry));

        $idea = Idea::sole();
        $this->assertSame('video', $idea->kind);
    }

    public function test_triage_requires_a_title_when_filing_an_idea()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        $response = $this->post(route('dashboard.scratchpad.triage', $entry), [
            'target' => 'post_idea',
        ]);

        $response->assertSessionHasErrors(['title']);
        $this->assertDatabaseCount('ideas', 0);
    }

    public function test_triage_as_drop_marks_the_entry_dropped_with_a_reason()
    {
        [$user, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        $response = $this->post(route('dashboard.scratchpad.triage', $entry), [
            'target' => 'drop',
            'drop_reason' => 'Not useful.',
        ]);

        $response->assertRedirect(route('dashboard.scratchpad.show', $entry));

        $this->assertDatabaseHas('scratchpad_entries', [
            'id' => $entry->id,
            'status' => 'dropped',
            'drop_reason' => 'Not useful.',
            'triaged_by_user_id' => $user->id,
        ]);
        $this->assertDatabaseCount('ideas', 0);
    }

    public function test_triage_requires_a_reason_when_dropping()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        $response = $this->post(route('dashboard.scratchpad.triage', $entry), [
            'target' => 'drop',
        ]);

        $response->assertSessionHasErrors(['drop_reason']);
    }

    public function test_triage_404s_for_an_entry_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->for($otherWorkspace)->create();

        $this->post(route('dashboard.scratchpad.triage', $entry), [
            'target' => 'drop',
            'drop_reason' => 'Nope.',
        ])->assertNotFound();
    }

    public function test_suggest_triage_renders_a_successful_suggestion()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->create(['body' => 'A raw capture.']);

        $this->app->instance(AiCompletionClientContract::class, new class implements AiCompletionClientContract
        {
            public function complete($credential, $systemPrompt, $userContent): AiCompletionResult
            {
                return AiCompletionResult::success(json_encode([
                    'title' => 'A suggested title',
                    'score' => 700,
                    'trend' => 'evergreen',
                    'rationale' => 'It is a good fit.',
                ]));
            }
        });
        AiProviderCredential::factory()->for($workspace)->create();

        $this->post(route('dashboard.scratchpad.suggest-triage', $entry), [
            'target' => 'post_idea',
        ])->assertInertia(fn (Assert $page) => $page
            ->component('scratchpad/show')
            ->where('suggestion.target', 'post_idea')
            ->where('suggestion.successful', true)
            ->where('suggestion.title', 'A suggested title')
            ->where('suggestion.score', 700)
            ->where('suggestion.trend', 'evergreen')
        );

        $this->assertDatabaseHas('scratchpad_entries', ['id' => $entry->id, 'status' => 'new']);
        $this->assertDatabaseCount('ideas', 0);
    }

    public function test_suggest_triage_renders_a_failure_honestly()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        $this->post(route('dashboard.scratchpad.suggest-triage', $entry), [
            'target' => 'video_idea',
        ])->assertInertia(fn (Assert $page) => $page
            ->component('scratchpad/show')
            ->where('suggestion.successful', false)
            ->where('suggestion.error', 'No AI-generated suggestion is available right now.')
        );
    }

    public function test_suggest_triage_rejects_an_invalid_target()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        $this->post(route('dashboard.scratchpad.suggest-triage', $entry), [
            'target' => 'drop',
        ])->assertSessionHasErrors(['target']);
    }

    public function test_suggest_triage_404s_for_an_entry_in_a_different_workspace()
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $entry = ScratchpadEntry::factory()->for($otherWorkspace)->create();

        $this->post(route('dashboard.scratchpad.suggest-triage', $entry), [
            'target' => 'post_idea',
        ])->assertNotFound();
    }

    public function test_an_already_triaged_entry_cannot_be_triaged_again()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $entry = ScratchpadEntry::factory()->for($workspace)->triaged()->create();

        $response = $this->post(route('dashboard.scratchpad.triage', $entry), [
            'target' => 'drop',
            'drop_reason' => 'Too late.',
        ]);

        $response->assertRedirect(route('dashboard.scratchpad.show', $entry));
        $response->assertInertiaFlash('toast.type', 'error');
    }

    public function test_store_link_creates_a_link_entry_and_queues_resolution()
    {
        Queue::fake();
        [, $workspace] = $this->actingAsWorkspaceMember();

        $response = $this->post(route('dashboard.scratchpad.link'), [
            'url' => 'https://example.com/a-post',
        ]);

        $response->assertRedirect(route('dashboard.scratchpad.index'));

        $entry = ScratchpadEntry::sole();
        $this->assertSame($workspace->id, $entry->workspace_id);
        $this->assertSame('link', $entry->kind);
        $this->assertSame('web', $entry->source);
        $this->assertSame('new', $entry->status);
        $this->assertSame('https://example.com/a-post', $entry->body);
        $this->assertSame('https://example.com/a-post', $entry->meta['url']);

        Queue::assertPushed(ResolveScratchpadLinkJob::class, fn (ResolveScratchpadLinkJob $job) => $job->entry->is($entry));
    }

    public function test_store_link_rejects_a_non_url_value()
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $response = $this->post(route('dashboard.scratchpad.link'), [
            'url' => 'not a url',
        ]);

        $response->assertSessionHasErrors('url');
        $this->assertSame(0, ScratchpadEntry::where('workspace_id', $workspace->id)->count());
    }

    public function test_store_photo_creates_a_photo_entry_with_a_media_asset_and_attachment()
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $response = $this->post(route('dashboard.scratchpad.photo'), [
            'photo' => UploadedFile::fake()->image('view.jpg', 300, 200),
            'caption' => 'From the roof',
        ]);

        $response->assertRedirect(route('dashboard.scratchpad.index'));

        $entry = ScratchpadEntry::sole();
        $this->assertSame($workspace->id, $entry->workspace_id);
        $this->assertSame('photo', $entry->kind);
        $this->assertSame('new', $entry->status);
        $this->assertSame('From the roof', $entry->body);

        $mediaAsset = MediaAsset::sole();
        $this->assertSame('image', $mediaAsset->kind);
        Storage::disk('scratchpad')->assertExists($mediaAsset->path);

        $this->assertDatabaseHas('attachments', [
            'attachable_type' => $entry->getMorphClass(),
            'attachable_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
            'role' => 'image',
        ]);
    }

    public function test_store_photo_validates_a_missing_file()
    {
        Storage::fake('scratchpad');
        $this->actingAsWorkspaceMember();

        $response = $this->post(route('dashboard.scratchpad.photo'), []);

        $response->assertSessionHasErrors(['photo']);
        $this->assertDatabaseCount('scratchpad_entries', 0);
    }

    public function test_store_voice_creates_a_voice_entry_with_a_media_asset_and_attachment()
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $response = $this->post(route('dashboard.scratchpad.voice'), [
            'audio' => UploadedFile::fake()->create('note.webm', 200, 'audio/webm'),
            'language' => 'bn',
        ]);

        $response->assertRedirect(route('dashboard.scratchpad.index'));

        $entry = ScratchpadEntry::sole();
        $this->assertSame($workspace->id, $entry->workspace_id);
        $this->assertSame('voice', $entry->kind);
        $this->assertSame('new', $entry->status);
        $this->assertSame('bn', $entry->language);

        $mediaAsset = MediaAsset::sole();
        $this->assertSame('audio', $mediaAsset->kind);
        Storage::disk('scratchpad')->assertExists($mediaAsset->path);

        $this->assertDatabaseHas('attachments', [
            'attachable_type' => $entry->getMorphClass(),
            'attachable_id' => $entry->id,
            'media_asset_id' => $mediaAsset->id,
            'role' => 'audio',
        ]);
    }

    public function test_store_voice_validates_a_missing_file()
    {
        Storage::fake('scratchpad');
        $this->actingAsWorkspaceMember();

        $response = $this->post(route('dashboard.scratchpad.voice'), []);

        $response->assertSessionHasErrors(['audio']);
        $this->assertDatabaseCount('scratchpad_entries', 0);
    }

    /**
     * `UploadedFile::fake()` never exercises real content-sniffing (its
     * getMimeType() is hardcoded to whatever the test declares), so it can't
     * catch what a genuine browser recording's actual bytes sniff to. This
     * uses a real `Illuminate\Http\UploadedFile` around real ffmpeg-encoded
     * audio-only WebM bytes (`ffmpeg -f lavfi -i "sine=..." -c:a libopus
     * out.webm`, truncated to the first cluster) to prove
     * StoreScratchpadVoiceRequest's mimetypes: rule really does accept what
     * a browser's MediaRecorder(audio/webm) upload's content-sniffed type
     * turns out to be: 'video/webm', not 'audio/webm', because PHP's
     * finfo/Symfony MimeTypes can't tell an audio-only Matroska/WebM
     * container from a video one by bytes alone.
     */
    public function test_store_voice_accepts_a_real_audio_only_webm_recording()
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $tmpPath = tempnam(sys_get_temp_dir(), 'webm');
        file_put_contents($tmpPath, base64_decode(
            'GkXfo59ChoEBQveBAULygQRC84EIQoKEd2VibUKHgQRChYECGFOAZwEAAAAAAEtzEU2bdLpNu4tTq4QVSalmUw=='
        ));
        $file = new UploadedFile($tmpPath, 'voice-note.webm', 'audio/webm', null, true);

        $response = $this->post(route('dashboard.scratchpad.voice'), [
            'audio' => $file,
        ]);
        unlink($tmpPath);

        $response->assertRedirect(route('dashboard.scratchpad.index'));
        $response->assertSessionHasNoErrors();

        $entry = ScratchpadEntry::sole();
        $this->assertSame($workspace->id, $entry->workspace_id);
        $this->assertSame('voice', $entry->kind);

        $mediaAsset = MediaAsset::sole();
        $this->assertSame('audio', $mediaAsset->kind);
        // Stored as the browser-declared type, not the content-sniffed
        // 'video/webm', so this app's own `mime.startsWith('audio/')` UI
        // check still renders an <audio> player for a real recording.
        $this->assertSame('audio/webm', $mediaAsset->mime);
    }

    /**
     * Same real-content proof as above, for the fragmented-MP4 shape a
     * browser produces for MediaRecorder(audio/mp4) (Safari's fallback,
     * also what this app's own frontend falls back to when webm/opus isn't
     * supported). Real bytes from `ffmpeg -f lavfi -i "sine=..." -c:a aac
     * -movflags frag_keyframe+empty_moov+default_base_moof out.mp4`,
     * content-sniffs as 'video/mp4', not 'audio/mp4'.
     */
    public function test_store_voice_accepts_a_real_audio_only_fragmented_mp4_recording()
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $tmpPath = tempnam(sys_get_temp_dir(), 'mp4');
        file_put_contents($tmpPath, base64_decode(
            'AAAAHGZ0eXBpc281AAACAGlzbzVpc282bXA0MQAAAr0='
        ));
        $file = new UploadedFile($tmpPath, 'voice-note.mp4', 'audio/mp4', null, true);

        $response = $this->post(route('dashboard.scratchpad.voice'), [
            'audio' => $file,
        ]);
        unlink($tmpPath);

        $response->assertRedirect(route('dashboard.scratchpad.index'));
        $response->assertSessionHasNoErrors();

        $entry = ScratchpadEntry::sole();
        $this->assertSame($workspace->id, $entry->workspace_id);
        $this->assertSame('voice', $entry->kind);

        $mediaAsset = MediaAsset::sole();
        $this->assertSame('audio', $mediaAsset->kind);
        $this->assertSame('audio/mp4', $mediaAsset->mime);
    }

    /**
     * Proves the fix for a real gap: the client-declared Content-Type on a
     * multipart upload is not checked by StoreScratchpadVoiceRequest's
     * mimetypes: rule at all (that rule only content-sniffs the bytes), so
     * without a whitelist an attacker could upload a file whose bytes
     * genuinely pass audio validation while declaring an arbitrary
     * Content-Type (e.g. "text/html") that then gets stored and later
     * replayed verbatim as the response Content-Type when the file is
     * served back (ScratchpadController::media()) — a stored-XSS-via-upload
     * vector for a same-origin polyglot file. The real audio-only WebM
     * bytes here are identical to the legitimate test above; only the
     * client-declared type differs.
     */
    public function test_store_voice_ignores_a_spoofed_client_content_type()
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $tmpPath = tempnam(sys_get_temp_dir(), 'webm');
        file_put_contents($tmpPath, base64_decode(
            'GkXfo59ChoEBQveBAULygQRC84EIQoKEd2VibUKHgQRChYECGFOAZwEAAAAAAEtzEU2bdLpNu4tTq4QVSalmUw=='
        ));
        $file = new UploadedFile($tmpPath, 'voice-note.webm', 'text/html', null, true);

        $response = $this->post(route('dashboard.scratchpad.voice'), [
            'audio' => $file,
        ]);
        unlink($tmpPath);

        $response->assertRedirect(route('dashboard.scratchpad.index'));
        $response->assertSessionHasNoErrors();

        $mediaAsset = MediaAsset::sole();
        $this->assertSame('application/octet-stream', $mediaAsset->mime);
        $this->assertNotSame('text/html', $mediaAsset->mime);
    }

    public function test_media_streams_back_a_workspaces_own_asset()
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $this->post(route('dashboard.scratchpad.photo'), [
            'photo' => UploadedFile::fake()->image('view.jpg'),
        ]);

        $mediaAsset = MediaAsset::sole();

        $response = $this->get(route('dashboard.scratchpad.media', $mediaAsset));

        $response->assertOk();
        $response->assertHeader('Content-Type', $mediaAsset->mime);
    }

    public function test_media_404s_for_an_asset_in_a_different_workspace()
    {
        Storage::fake('scratchpad');
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $otherEntry = ScratchpadEntry::factory()->for($otherWorkspace)->create(['kind' => 'photo', 'body' => null]);
        $mediaAsset = MediaAsset::factory()->for($otherWorkspace)->create();
        Attachment::factory()->for($otherEntry, 'attachable')->for($mediaAsset)->create();

        $this->get(route('dashboard.scratchpad.media', $mediaAsset))->assertNotFound();
    }

    public function test_index_includes_attachments_for_a_photo_entry()
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $this->post(route('dashboard.scratchpad.photo'), [
            'photo' => UploadedFile::fake()->image('view.jpg'),
        ]);

        $entry = ScratchpadEntry::sole();

        $this->get(route('dashboard.scratchpad.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('scratchpad/index')
                ->has('entries.data.0.attachments', 1)
                ->where('entries.data.0.attachments.0.media_url', route('dashboard.scratchpad.media', $entry->attachments()->sole()->media_asset_id))
            );
    }
}
