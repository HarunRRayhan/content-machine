<?php

namespace Tests\Unit\Actions\Media;

use App\Actions\Media\CleanupOrphanMediaAction;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class CleanupOrphanMediaActionTest extends TestCase
{
    public function test_it_enforces_the_minimum_grace_period_for_direct_callers(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The orphan-media grace period cannot be shorter than 168 hours.');

        (new CleanupOrphanMediaAction)->handle(graceHours: 167);
    }

    public function test_it_refuses_a_non_local_scratchpad_disk(): void
    {
        config(['filesystems.disks.scratchpad.driver' => 's3']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Orphan-media cleanup only supports the local scratchpad disk.');

        (new CleanupOrphanMediaAction)->handle();
    }

    public function test_it_refuses_a_symlinked_scratchpad_root(): void
    {
        $realRoot = sys_get_temp_dir().'/cm-orphan-root-'.uniqid('', true);
        $linkedRoot = $realRoot.'-link';
        mkdir($realRoot);
        symlink($realRoot, $linkedRoot);
        config(['filesystems.disks.scratchpad.root' => $linkedRoot]);
        Storage::forgetDisk('scratchpad');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The local scratchpad root is unavailable.');

            (new CleanupOrphanMediaAction)->handle();
        } finally {
            Storage::forgetDisk('scratchpad');
            unlink($linkedRoot);
            rmdir($realRoot);
        }
    }
}
