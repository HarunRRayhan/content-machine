<?php

namespace Tests\Feature\Media;

use App\Models\Attachment;
use App\Models\MediaAsset;
use App\Models\ScratchpadEntry;
use App\Models\Transcription;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupOrphanMediaCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('scratchpad');
    }

    public function test_it_reports_old_unreferenced_managed_files_without_deleting_them(): void
    {
        $workspaceId = (string) Workspace::factory()->create()->id;
        $path = $this->putOldUpload($workspaceId);
        $pexelsPath = $workspaceId.'/source-pexels-123-'.str()->ulid().'.webp';
        $disk = Storage::disk('scratchpad');
        $disk->put($pexelsPath, 'pexels orphan');
        $this->makeOld($disk->path($pexelsPath));

        $this->artisan('cm:cleanup-orphan-media')
            ->expectsOutputToContain("orphan: {$path}")
            ->expectsOutputToContain("orphan: {$pexelsPath}")
            ->expectsOutputToContain('2 eligible orphan(s)')
            ->assertExitCode(0);

        $this->assertTrue(Storage::disk('scratchpad')->exists($path));
    }

    public function test_it_deletes_only_explicitly_confirmed_old_orphan_files(): void
    {
        $workspaceId = (string) Workspace::factory()->create()->id;
        $path = $this->putOldUpload($workspaceId);
        $recentPath = $workspaceId.'/'.str()->ulid().'.jpg';
        Storage::disk('scratchpad')->put($recentPath, 'recent');

        $this->artisan('cm:cleanup-orphan-media', ['--delete' => true])
            ->expectsOutputToContain("quarantined: {$path}")
            ->expectsOutputToContain('1 quarantined')
            ->assertExitCode(0);

        $this->assertFalse(Storage::disk('scratchpad')->exists($path));
        $this->assertTrue(Storage::disk('scratchpad')->exists($recentPath));

        $disk = Storage::disk('scratchpad');
        $quarantinePath = glob($disk->path('.cm-orphan-quarantine/*/'.$path))[0];
        $oldQuarantineAt = now('UTC')->subDays(8)->format('YmdHis');
        $oldQuarantinePath = $disk->path('.cm-orphan-quarantine/'.$oldQuarantineAt.'/'.$path);
        if (! is_dir(dirname($oldQuarantinePath))) {
            mkdir(dirname($oldQuarantinePath), 0777, true);
        }
        rename($quarantinePath, $oldQuarantinePath);

        $this->artisan('cm:cleanup-orphan-media', ['--delete' => true])
            ->expectsOutputToContain("deleted: {$path}")
            ->expectsOutputToContain('1 permanently deleted')
            ->assertExitCode(0);

        $this->assertFalse($disk->exists('.cm-orphan-quarantine/'.$oldQuarantineAt.'/'.$path));
    }

    public function test_it_keeps_a_recent_upload_file_when_its_database_transaction_rolls_back(): void
    {
        $workspace = Workspace::factory()->create();
        $path = $this->putOldUpload((string) $workspace->id);
        touch(Storage::disk('scratchpad')->path($path), now()->subMinutes(5)->getTimestamp());

        try {
            DB::transaction(function () use ($workspace, $path): void {
                MediaAsset::factory()->for($workspace)->create([
                    'disk' => 'scratchpad',
                    'path' => $path,
                ]);

                throw new \RuntimeException('simulate upload transaction rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulate upload transaction rollback', $exception->getMessage());
        }

        $this->artisan('cm:cleanup-orphan-media', ['--delete' => true])
            ->expectsOutputToContain('within_grace_period')
            ->assertExitCode(0);

        $this->assertTrue(Storage::disk('scratchpad')->exists($path));
        $this->assertDatabaseMissing('media_assets', ['path' => $path]);
    }

    public function test_it_preserves_assets_referenced_by_attachments_and_transcriptions(): void
    {
        $workspace = Workspace::factory()->create();
        $path = $this->putOldUpload((string) $workspace->id);
        $asset = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => $path,
        ]);
        $entry = ScratchpadEntry::factory()->create();
        Attachment::factory()->create([
            'attachable_type' => ScratchpadEntry::class,
            'attachable_id' => $entry->id,
            'media_asset_id' => $asset->id,
        ]);
        Transcription::factory()->create(['media_asset_id' => $asset->id]);

        $this->artisan('cm:cleanup-orphan-media', ['--delete' => true])
            ->doesntExpectOutputToContain($path)
            ->assertExitCode(0);

        $this->assertTrue(Storage::disk('scratchpad')->exists($path));
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id, 'path' => $path]);
    }

    public function test_it_restores_a_quarantined_file_if_a_media_asset_reference_appears(): void
    {
        $workspace = Workspace::factory()->create();
        $path = $this->putOldUpload((string) $workspace->id);
        $disk = Storage::disk('scratchpad');

        $this->artisan('cm:cleanup-orphan-media', ['--delete' => true])->assertExitCode(0);

        $quarantinePath = glob($disk->path('.cm-orphan-quarantine/*/'.$path))[0];
        $oldQuarantineAt = now('UTC')->subDays(8)->format('YmdHis');
        $oldQuarantinePath = $disk->path('.cm-orphan-quarantine/'.$oldQuarantineAt.'/'.$path);
        if (! is_dir(dirname($oldQuarantinePath))) {
            mkdir(dirname($oldQuarantinePath), 0777, true);
        }
        rename($quarantinePath, $oldQuarantinePath);
        $asset = MediaAsset::factory()->for($workspace)->create([
            'disk' => 'scratchpad',
            'path' => $path,
        ]);

        $this->artisan('cm:cleanup-orphan-media', ['--delete' => true])
            ->expectsOutputToContain("restored: {$path}")
            ->assertExitCode(0);

        $this->assertTrue($disk->exists($path));
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id, 'path' => $path]);
    }

    public function test_it_ignores_wrong_prefixes_and_rejects_a_short_grace_period(): void
    {
        $disk = Storage::disk('scratchpad');
        $wrongPrefix = 'external/'.str()->ulid().'.jpg';
        $wrongDepth = '42/nested/'.str()->ulid().'.jpg';
        $disk->put($wrongPrefix, 'external');
        $disk->put($wrongDepth, 'nested');
        $this->makeOld($disk->path($wrongPrefix));
        $this->makeOld($disk->path($wrongDepth));

        $this->artisan('cm:cleanup-orphan-media', ['--delete' => true, '--grace-hours' => 24])
            ->expectsOutputToContain('at least 168 hours')
            ->assertExitCode(1);

        $this->artisan('cm:cleanup-orphan-media')
            ->doesntExpectOutputToContain($wrongPrefix)
            ->doesntExpectOutputToContain($wrongDepth)
            ->assertExitCode(0);

        $this->assertTrue($disk->exists($wrongPrefix));
        $this->assertTrue($disk->exists($wrongDepth));
    }

    public function test_it_fails_closed_when_scratchpad_is_not_a_local_disk(): void
    {
        config(['filesystems.disks.scratchpad.driver' => 's3']);

        $this->artisan('cm:cleanup-orphan-media', ['--delete' => true])
            ->expectsOutputToContain('only supports the local scratchpad disk')
            ->assertExitCode(1);
    }

    public function test_it_skips_symlinked_files_and_never_traverses_outside_the_disk_root(): void
    {
        $disk = Storage::disk('scratchpad');
        $workspaceId = (string) Workspace::factory()->create()->id;
        $linkedWorkspaceId = (string) Workspace::factory()->create()->id;
        $filename = (string) str()->ulid().'.jpg';
        $outsideRoot = sys_get_temp_dir().'/cm-orphan-outside-'.uniqid('', true);
        file_put_contents($outsideRoot, 'keep');
        $insideDirectory = $disk->path($workspaceId);
        if (! is_dir($insideDirectory)) {
            mkdir($insideDirectory, 0777, true);
        }
        symlink($outsideRoot, $insideDirectory.DIRECTORY_SEPARATOR.$filename);

        $outsideDirectory = sys_get_temp_dir().'/cm-orphan-outside-dir-'.uniqid('', true);
        mkdir($outsideDirectory);
        $outsideCandidate = $outsideDirectory.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($outsideCandidate, 'keep outside directory');
        touch($outsideCandidate, now()->subDays(8)->getTimestamp());
        symlink($outsideDirectory, $disk->path($linkedWorkspaceId));

        $quarantineRoot = $disk->path('.cm-orphan-quarantine');
        mkdir($quarantineRoot);
        $quarantineOutside = sys_get_temp_dir().'/cm-orphan-quarantine-outside-'.uniqid('', true);
        mkdir($quarantineOutside);
        $quarantineCandidate = $quarantineOutside.DIRECTORY_SEPARATOR.$linkedWorkspaceId.DIRECTORY_SEPARATOR.$filename;
        mkdir(dirname($quarantineCandidate));
        file_put_contents($quarantineCandidate, 'keep quarantined outside directory');
        $oldQuarantineBucket = now('UTC')->subDays(8)->format('YmdHis');
        symlink($quarantineOutside, $quarantineRoot.DIRECTORY_SEPARATOR.$oldQuarantineBucket);

        try {
            $this->artisan('cm:cleanup-orphan-media', ['--delete' => true])
                ->doesntExpectOutputToContain($filename)
                ->assertExitCode(0);

            $this->assertFileExists($outsideRoot);
            $this->assertFileExists($outsideCandidate);
            $this->assertFileExists($quarantineCandidate);
            $this->assertTrue(is_link($insideDirectory.DIRECTORY_SEPARATOR.$filename));
            $this->assertTrue(is_link($disk->path($linkedWorkspaceId)));
            $this->assertTrue(is_link($quarantineRoot.DIRECTORY_SEPARATOR.$oldQuarantineBucket));
        } finally {
            @unlink($insideDirectory.DIRECTORY_SEPARATOR.$filename);
            @unlink($outsideRoot);
            @unlink($disk->path($linkedWorkspaceId));
            @unlink($quarantineRoot.DIRECTORY_SEPARATOR.$oldQuarantineBucket);
            @unlink($outsideCandidate);
            @rmdir($outsideDirectory);
            @unlink($quarantineCandidate);
            @rmdir(dirname($quarantineCandidate));
            @rmdir($quarantineOutside);
        }
    }

    private function putOldUpload(string $workspaceId): string
    {
        $path = $workspaceId.'/'.str()->ulid().'.jpg';
        $disk = Storage::disk('scratchpad');
        $disk->put($path, 'old orphan');
        $this->makeOld($disk->path($path));

        return $path;
    }

    private function makeOld(string $path): void
    {
        touch($path, now()->subDays(8)->getTimestamp());
    }
}
