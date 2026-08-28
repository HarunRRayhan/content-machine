<?php

namespace Tests\Feature\Media;

use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MediaLibraryControllerTest extends TestCase
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

    public function test_guests_are_redirected_from_media_library(): void
    {
        $this->get(route('media.images'))->assertRedirect(route('login'));
    }

    public function test_media_index_redirects_to_images(): void
    {
        $this->actingAsWorkspaceMember();

        $this->get(route('media.index'))->assertRedirect(route('media.images'));
    }

    public function test_images_tab_excludes_gifs_and_videos(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $image = MediaAsset::factory()->for($workspace)->create([
            'kind' => 'image',
            'mime' => 'image/jpeg',
            'title' => 'Photo',
        ]);
        MediaAsset::factory()->for($workspace)->create([
            'kind' => 'image',
            'mime' => 'image/gif',
            'title' => 'Animated',
        ]);
        MediaAsset::factory()->for($workspace)->create([
            'kind' => 'video',
            'mime' => 'video/mp4',
            'title' => 'Clip',
        ]);

        $this->get(route('media.images'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('media/index')
                ->where('tab', 'images')
                ->has('items.data', 1)
                ->where('items.data.0.public_id', $image->public_id)
            );
    }

    public function test_gifs_tab_only_lists_gifs(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $gif = MediaAsset::factory()->for($workspace)->create([
            'kind' => 'image',
            'mime' => 'image/gif',
            'title' => 'Loop',
        ]);
        MediaAsset::factory()->for($workspace)->create([
            'kind' => 'image',
            'mime' => 'image/jpeg',
        ]);

        $this->get(route('media.gifs'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('media/index')
                ->where('tab', 'gifs')
                ->has('items.data', 1)
                ->where('items.data.0.public_id', $gif->public_id)
            );
    }

    public function test_store_uploads_library_media_and_redirects_to_show(): void
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $response = $this->post(route('media.store'), [
            'tab' => 'images',
            'file' => UploadedFile::fake()->image('cover.jpg', 800, 600),
            'title' => 'Cover photo',
            'description' => 'Orange border template cover.',
        ]);

        $asset = MediaAsset::query()->sole();

        $response->assertRedirect(route('media.show', $asset));

        $this->assertSame($workspace->id, $asset->workspace_id);
        $this->assertSame('Cover photo', $asset->title);
        $this->assertSame('Orange border template cover.', $asset->description);
        $this->assertSame('library', $asset->meta['source'] ?? null);
        Storage::disk('scratchpad')->assertExists($asset->path);
    }

    public function test_show_is_scoped_to_the_current_workspace(): void
    {
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $foreign = MediaAsset::factory()->for($otherWorkspace)->create([
            'title' => 'Foreign',
        ]);

        $this->get(route('media.show', $foreign))->assertNotFound();
    }

    public function test_update_persists_title_and_description(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $asset = MediaAsset::factory()->for($workspace)->create([
            'title' => 'Old title',
            'description' => null,
        ]);

        $this->patch(route('media.update', $asset), [
            'title' => 'New title',
            'description' => 'Agent-facing notes.',
        ])->assertRedirect(route('media.show', $asset));

        $asset->refresh();

        $this->assertSame('New title', $asset->title);
        $this->assertSame('Agent-facing notes.', $asset->description);
    }

    public function test_destroy_is_blocked_when_asset_is_still_attached(): void
    {
        [, $workspace] = $this->actingAsWorkspaceMember();

        $asset = MediaAsset::factory()->for($workspace)->create();
        $entry = ScratchpadEntry::factory()->for($workspace)->create();

        Attachment::factory()->create([
            'media_asset_id' => $asset->id,
            'attachable_type' => $entry->getMorphClass(),
            'attachable_id' => $entry->id,
        ]);

        $this->delete(route('media.destroy', $asset))
            ->assertRedirect(route('media.show', $asset));

        $this->assertModelExists($asset);
    }

    public function test_destroy_deletes_unattached_library_media(): void
    {
        Storage::fake('scratchpad');
        [, $workspace] = $this->actingAsWorkspaceMember();

        $asset = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => 'media/test.jpg',
            'meta' => ['source' => 'library'],
        ]);
        Storage::disk('scratchpad')->put($asset->path, 'bytes');

        $this->delete(route('media.destroy', $asset))
            ->assertRedirect(route('media.images'));

        $this->assertModelMissing($asset);
        Storage::disk('scratchpad')->assertMissing('media/test.jpg');
    }

    public function test_file_stream_is_scoped_to_the_current_workspace(): void
    {
        Storage::fake('scratchpad');
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $foreign = MediaAsset::factory()->for($otherWorkspace)->create([
            'disk' => 'scratchpad',
            'path' => 'media/foreign.jpg',
            'mime' => 'image/jpeg',
        ]);
        Storage::disk('scratchpad')->put($foreign->path, 'foreign');

        $this->get(route('media.file', $foreign))->assertNotFound();
    }
}
