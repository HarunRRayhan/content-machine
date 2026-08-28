<?php

namespace Tests\Feature\Media;

use App\Actions\Media\SyncPresentationLibraryAction;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SyncPresentationLibraryActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function actingAsWorkspaceMember(): array
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        return [$user, $workspace];
    }

    public function test_sync_creates_media_assets_for_every_presentation_svg(): void
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $synced = (new SyncPresentationLibraryAction)->handle($workspace);

        $this->assertGreaterThan(0, $synced);

        $assets = MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->where('meta->source', 'presentation_library')
            ->get();

        $this->assertGreaterThanOrEqual(60, $assets->count());
        $this->assertTrue($assets->every(fn (MediaAsset $asset) => $asset->mime === 'image/svg+xml'));
    }

    public function test_sync_is_idempotent(): void
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $action = new SyncPresentationLibraryAction;
        $first = $action->handle($workspace);
        $second = $action->handle($workspace);

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second);
    }

    public function test_images_tab_lists_presentation_library_assets(): void
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        (new SyncPresentationLibraryAction)->handle($workspace);

        $this->get(route('media.images'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('media/index')
                ->where('tab', 'images')
                ->has('items.data', 24)
                ->where('items.total', fn ($total) => $total >= 60)
            );
    }

    public function test_presentation_library_assets_cannot_be_deleted(): void
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        (new SyncPresentationLibraryAction)->handle($workspace);

        $asset = MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->where('meta->source', 'presentation_library')
            ->firstOrFail();

        $this->delete(route('media.destroy', $asset))
            ->assertRedirect(route('media.show', $asset));

        $this->assertModelExists($asset);
    }

    public function test_show_lists_video_deck_usages_for_presentation_assets(): void
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        (new SyncPresentationLibraryAction)->handle($workspace);

        $asset = MediaAsset::query()
            ->where('workspace_id', $workspace->id)
            ->where('meta->asset_key', 'icon-wifi')
            ->firstOrFail();

        Video::factory()->for($workspace)->create([
            'human_id' => 'BV-99',
            'number' => 99,
            'deck_manifest' => [
                'engine' => 'stage',
                'js' => "const x = PA('icon-wifi');",
            ],
        ]);

        $this->get(route('media.show', $asset))
            ->assertInertia(fn (Assert $page) => $page
                ->component('media/show')
                ->where('asset.source.type', 'presentation_library')
                ->where('asset.deletable', false)
                ->where('asset.presentation_asset_key', 'icon-wifi')
                ->where('asset.usages.0.label', 'BV-99')
            );
    }
}
