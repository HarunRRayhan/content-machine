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
            'js' => "window.PRESENTATIONS['test-deck']={steps:[{cue:'First spoken line'},{cue:'Second line',scriptLine:1,scriptCue:'Second spoken line'}],stage:function(){return '<div>Deck</div>';}};",
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
        $this->assertStringContainsString('id="presFrame"', $html);
        $this->assertStringContainsString('sandbox="allow-scripts"', $html);
        $this->assertStringNotContainsString('First spoken line', $html);
        $this->assertStringNotContainsString('body.embed .pres-notes{display:none}', $html);
        $this->assertStringNotContainsString('body.embed .pres-notes{display: none}', $html);
        $this->assertStringContainsString('event.origin !== parentOrigin', $html);
        $childAt = strpos($html, 'event.source === frameEl?.contentWindow');
        $parentGateAt = strpos($html, 'event.source !== window.parent');
        $this->assertNotFalse($childAt);
        $this->assertNotFalse($parentGateAt);
        $this->assertLessThan($parentGateAt, $childAt);
        $this->assertStringContainsString('background:var(--cm-bg)', $html);
        $this->assertStringNotContainsString('#presShell.is-fullscreen .pres-cue{background:#f6f7f8', $html);
        $this->assertStringContainsString('.pres-cue-row:hover .pres-cue-editbtn', $html);
    }

    public function test_presentation_is_always_rendered_with_the_fixed_light_theme(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $video = Video::factory()->for($workspace)->create([
            'deck_manifest' => $this->deckManifest(),
        ]);

        $hostHtml = $this->get(route('videos.presentation', [
            'video' => $video,
            'theme' => 'dark',
        ]))->assertOk()->getContent();

        $frameHtml = $this->get(route('videos.presentation.frame', [
            'video' => $video,
            'theme' => 'dark',
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('class="dark"', $hostHtml);
        $this->assertStringNotContainsString('html.dark', $hostHtml);
        $this->assertStringNotContainsString('theme=dark', $hostHtml);
        $this->assertStringNotContainsString('class="dark"', $frameHtml);
        $this->assertStringNotContainsString('html.dark', $frameHtml);
        $this->assertStringContainsString('--bg: #eef0f1', $frameHtml);
    }

    public function test_presentation_host_allows_its_sandboxed_deck_frame(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $video = Video::factory()->for($workspace)->create([
            'deck_manifest' => $this->deckManifest(),
        ]);

        $this->get(route('videos.presentation', $video))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }

    public function test_frame_is_sandboxed_and_contains_the_deck_package(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $video = Video::factory()->for($workspace)->create([
            'deck_manifest' => $this->deckManifest(),
        ]);

        $this->get(route('videos.presentation.frame', $video))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'unsafe-inline' https://cdn.jsdelivr.net; img-src data: blob:; media-src data: blob:; font-src data: https:; connect-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'")
            ->assertSee('First spoken line', false)
            ->assertSee('source: \'cm-pres-frame\'', false);
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
        $this->assertDatabaseCount('content_versions', 2);
        $this->assertDatabaseHas('content_versions', [
            'versionable_type' => $video->getMorphClass(),
            'versionable_id' => $video->id,
            'field' => 'script_markdown',
        ]);
        $this->assertDatabaseHas('content_versions', [
            'versionable_type' => $video->getMorphClass(),
            'versionable_id' => $video->id,
            'field' => 'deck_manifest',
        ]);
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

    public function test_update_cue_rejects_a_publish_locked_video(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();
        $video = Video::factory()->for($workspace)->create([
            'script_markdown' => "```\n[HOOK]\nFirst spoken line\n```",
            'deck_manifest' => $this->deckManifest(),
            'publish_state' => 'running',
        ]);

        $this->patchJson(route('videos.presentation.cue', $video), [
            'step' => 0,
            'current_cue' => 'First spoken line',
            'cue' => 'Updated line',
        ])->assertStatus(422)->assertJson(['ok' => false]);

        $this->assertDatabaseCount('content_versions', 0);
        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'script_markdown' => "```\n[HOOK]\nFirst spoken line\n```",
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
