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
            'js' => "window.PRESENTATIONS['test-deck']={steps:[{cue:'First spoken line'},{cue:'Second spoken line'}],stage:function(){return '<div>Deck</div>';}};",
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
        $this->assertStringContainsString('First spoken line', $html);
        $this->assertStringNotContainsString('body.embed .pres-notes{display:none}', $html);
        $this->assertStringNotContainsString('body.embed .pres-notes{display: none}', $html);
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
