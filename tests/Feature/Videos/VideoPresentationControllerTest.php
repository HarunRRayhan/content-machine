<?php

namespace Tests\Feature\Videos;

use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoPresentationControllerTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function deckManifest(): array
    {
        return [
            'engine' => 'stage',
            'deck_key' => 'test-deck',
            'css' => '',
            'js' => "window.PRESENTATIONS['test-deck']={steps:[{cue:'First spoken line'},{cue:'Second line'}],stage:function(){return '<div>Deck</div>';}};",
        ];
    }

    public function test_guests_cannot_view_a_presentation(): void
    {
        $video = Video::factory()->create([
            'deck_manifest' => $this->deckManifest(),
        ]);

        $this->get(route('videos.presentation', $video))
            ->assertRedirect(route('login'));
    }

    public function test_show_404s_when_the_video_has_no_deck(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $video = Video::factory()->for($workspace)->create([
            'deck_manifest' => null,
        ]);

        $this->get(route('videos.presentation', $video))->assertNotFound();
    }

    public function test_show_404s_when_the_deck_manifest_has_no_javascript(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $video = Video::factory()->for($workspace)->create([
            'deck_manifest' => ['engine' => 'reveal', 'js' => ''],
        ]);

        $this->get(route('videos.presentation', $video))->assertNotFound();
    }

    public function test_show_404s_when_the_deck_manifest_has_no_registered_renderer(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $video = Video::factory()->for($workspace)->create([
            'deck_manifest' => [
                'deck_key' => 'test-deck',
                'js' => '1',
            ],
        ]);

        $this->get(route('videos.presentation', $video))->assertNotFound();
    }

    public function test_show_404s_for_a_video_in_a_different_workspace(): void
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $video = Video::factory()->for($otherWorkspace)->create([
            'deck_manifest' => $this->deckManifest(),
        ]);

        $this->get(route('videos.presentation', $video))->assertNotFound();
    }

    public function test_embed_keeps_the_script_notes_column(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $video = Video::factory()->for($workspace)->create([
            'deck_manifest' => $this->deckManifest(),
        ]);

        $html = $this->get(route('videos.presentation', [
            'video' => $video,
            'embed' => 1,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('id="presNotes"', $html);
        $this->assertStringContainsString('id="presCue"', $html);
        $this->assertStringContainsString('id="presCueEditBtn"', $html);
        $this->assertStringContainsString('id="presCueEditor"', $html);
        $this->assertStringContainsString('First spoken line', $html);
        $this->assertStringNotContainsString('body.embed .pres-notes{display:none}', $html);
        $this->assertStringNotContainsString('body.embed .pres-notes{display: none}', $html);
    }

    public function test_update_cue_updates_the_script_and_deck(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $video = Video::factory()->for($workspace)->create([
            'script_markdown' => <<<'MD'
# Test video

```
[HOOK]
First spoken line

Second spoken line [on-screen: second line]
```
MD,
            'deck_manifest' => $this->deckManifest(),
        ]);

        $this->patchJson(route('videos.presentation.cue', $video), [
            'step' => 1,
            'current_cue' => 'Second line',
            'cue' => 'Updated line',
        ])
            ->assertOk()
            ->assertJson(['ok' => true, 'cue' => 'Updated line']);

        $video->refresh();
        $this->assertStringContainsString('Updated line [on-screen: second line]', $video->script_markdown);
        $this->assertStringNotContainsString('Second spoken line', $video->script_markdown);
        $this->assertStringContainsString("cue:'Updated line'", $video->deck_manifest['js']);
    }

    public function test_update_cue_rejects_a_stale_step(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $script = "```\n[HOOK]\nFirst spoken line\n```";
        $video = Video::factory()->for($workspace)->create([
            'script_markdown' => $script,
            'deck_manifest' => $this->deckManifest(),
        ]);

        $this->patchJson(route('videos.presentation.cue', $video), [
            'step' => 0,
            'current_cue' => 'No longer here',
            'cue' => 'Updated spoken line',
        ])->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'script_markdown' => $script,
        ]);
    }

    public function test_update_cue_escapes_script_tags_in_the_deck(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $video = Video::factory()->for($workspace)->create([
            'script_markdown' => "```\n[HOOK]\nFirst spoken line\n```",
            'deck_manifest' => $this->deckManifest(),
        ]);

        $this->patchJson(route('videos.presentation.cue', $video), [
            'step' => 0,
            'current_cue' => 'First spoken line',
            'cue' => '</script><script>alert(1)</script>',
        ])->assertOk();

        $video->refresh();
        $this->assertStringContainsString('</script><script>alert(1)</script>', $video->script_markdown);
        $this->assertStringContainsString("'\\x3C/script>\\x3Cscript>alert(1)\\x3C/script>'", $video->deck_manifest['js']);
        $this->assertStringNotContainsString("'</script><script>alert(1)</script>'", $video->deck_manifest['js']);
    }

    public function test_chrome_uses_content_machine_colors_not_studio_cream(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $video = Video::factory()->for($workspace)->create([
            'deck_manifest' => $this->deckManifest(),
        ]);

        $html = $this->get(route('videos.presentation', $video))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('#efe7d8', $html);
        $this->assertStringNotContainsString('#faf5ea', $html);
        $this->assertStringContainsString('--cm-bg:', $html);
    }
}
