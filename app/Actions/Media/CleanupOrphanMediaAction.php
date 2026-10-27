<?php

namespace App\Actions\Media;

use App\Data\Media\OrphanMediaCleanupResultData;
use App\Models\MediaAsset;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Finder\Finder;

class CleanupOrphanMediaAction
{
    private const MINIMUM_GRACE_HOURS = 168;

    public function handle(bool $delete = false, int $graceHours = self::MINIMUM_GRACE_HOURS): OrphanMediaCleanupResultData
    {
        if ($graceHours < self::MINIMUM_GRACE_HOURS) {
            throw new RuntimeException('The orphan-media grace period cannot be shorter than 168 hours.');
        }

        $diskConfig = config('filesystems.disks.scratchpad');
        if (! is_array($diskConfig) || ($diskConfig['driver'] ?? null) !== 'local') {
            throw new RuntimeException('Orphan-media cleanup only supports the local scratchpad disk.');
        }

        $disk = Storage::disk('scratchpad');
        $diskRoot = rtrim($disk->path(''), DIRECTORY_SEPARATOR);
        $diskRoot = $diskRoot === '' ? DIRECTORY_SEPARATOR : $diskRoot;
        $root = realpath($diskRoot);
        if ($root === false || ! is_dir($root) || is_link($diskRoot)) {
            throw new RuntimeException('The local scratchpad root is unavailable.');
        }

        $cutoff = (new DateTimeImmutable)->modify('-'.$graceHours.' hours')->getTimestamp();
        $files = [];
        $deleted = 0;
        $skipped = 0;

        foreach ($this->managedFilePaths($root) as $path) {
            $absolutePath = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);

            if (! $this->isManagedPath($path)) {
                continue;
            }

            clearstatcache(true, $absolutePath);
            $modifiedAt = $this->fileModifiedAt($absolutePath);
            if ($modifiedAt === false || $modifiedAt > $cutoff) {
                $files[] = ['path' => $path, 'status' => 'skipped', 'reason' => 'within_grace_period'];
                $skipped++;

                continue;
            }

            if ($this->isReferenced($path)) {
                continue;
            }

            if (! $delete) {
                $files[] = ['path' => $path, 'status' => 'orphan', 'reason' => 'unreferenced_past_grace_period'];

                continue;
            }

            // Upload writers do not share a lock with this command. Move an
            // eligible file to quarantine first; a later run can permanently
            // delete it only after another full grace period.
            clearstatcache(true, $absolutePath);
            $freshRoot = realpath($root);
            $freshPath = realpath($absolutePath);
            $freshModifiedAt = $this->fileModifiedAt($absolutePath);

            if ($freshRoot !== $root
                || $freshPath === false
                || ! str_starts_with($freshPath, $root.DIRECTORY_SEPARATOR)
                || is_link($absolutePath)
                || $freshModifiedAt === false
                || $freshModifiedAt > $cutoff
                || $this->isReferenced($path)) {
                $files[] = ['path' => $path, 'status' => 'skipped', 'reason' => 'changed_during_scan'];
                $skipped++;

                continue;
            }

            $quarantineAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('YmdHis');
            $quarantinePath = '.cm-orphan-quarantine/'.$quarantineAt.'/'.$path;
            if ($disk->move($path, $quarantinePath)) {
                if ($this->isReferenced($path) && ! $disk->exists($path)) {
                    if ($disk->move($quarantinePath, $path)) {
                        $files[] = ['path' => $path, 'status' => 'restored', 'reason' => 'media_asset_reference_found'];
                    } else {
                        $files[] = ['path' => $path, 'status' => 'skipped', 'reason' => 'quarantine_restore_failed'];
                        $skipped++;
                    }
                } else {
                    $files[] = ['path' => $path, 'status' => 'quarantined', 'reason' => 'confirmed_unreferenced'];
                }
            } else {
                $files[] = ['path' => $path, 'status' => 'skipped', 'reason' => 'quarantine_move_failed'];
                $skipped++;
            }
        }

        foreach ($this->quarantinedFilePaths($root) as $quarantined) {
            [$quarantineAt, $originalPath] = $quarantined;
            $quarantinePath = '.cm-orphan-quarantine/'.$quarantineAt.'/'.$originalPath;
            $quarantineAbsolutePath = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $quarantinePath);
            $quarantineTimestamp = DateTimeImmutable::createFromFormat('!YmdHis', $quarantineAt, new DateTimeZone('UTC'));
            if ($quarantineTimestamp === false || $quarantineTimestamp->getTimestamp() > $cutoff) {
                $files[] = ['path' => $originalPath, 'status' => 'skipped', 'reason' => 'quarantine_grace_period'];
                $skipped++;

                continue;
            }

            if (! $delete) {
                $files[] = ['path' => $originalPath, 'status' => 'quarantined', 'reason' => 'eligible_for_permanent_deletion'];

                continue;
            }

            clearstatcache(true, $quarantineAbsolutePath);
            $freshRoot = realpath($root);
            $freshQuarantinePath = realpath($quarantineAbsolutePath);
            if ($freshRoot !== $root
                || $freshQuarantinePath === false
                || ! str_starts_with($freshQuarantinePath, $root.DIRECTORY_SEPARATOR)
                || is_link($quarantineAbsolutePath)) {
                $files[] = ['path' => $originalPath, 'status' => 'skipped', 'reason' => 'changed_during_quarantine'];
                $skipped++;

                continue;
            }

            if ($this->isReferenced($originalPath)) {
                if (! $disk->exists($originalPath) && $disk->move($quarantinePath, $originalPath)) {
                    $files[] = ['path' => $originalPath, 'status' => 'restored', 'reason' => 'media_asset_reference_found'];
                } else {
                    $files[] = ['path' => $originalPath, 'status' => 'skipped', 'reason' => 'media_asset_reference_found'];
                    $skipped++;
                }

                continue;
            }

            if ($disk->delete($quarantinePath)) {
                $files[] = ['path' => $originalPath, 'status' => 'deleted', 'reason' => 'quarantined_past_grace_period'];
                $deleted++;
            } else {
                $files[] = ['path' => $originalPath, 'status' => 'skipped', 'reason' => 'storage_delete_failed'];
                $skipped++;
            }
        }

        return new OrphanMediaCleanupResultData($files, $deleted, $skipped);
    }

    /** @return list<string> */
    private function managedFilePaths(string $root): array
    {
        $paths = [];
        $finder = Finder::create()->files()->in($root)->ignoreDotFiles(true)->ignoreVCS(true);

        foreach ($finder as $file) {
            $absolutePath = $file->getPathname();
            if (is_link($absolutePath)) {
                continue;
            }

            $realPath = realpath($absolutePath);
            if ($realPath === false || ! str_starts_with($realPath, $root.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($root) + 1));
            if ($this->isManagedPath($relativePath)) {
                $paths[] = $relativePath;
            }
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    private function isManagedPath(string $path): bool
    {
        $ulid = '[0-9A-HJKMNP-TV-Z]{26}';
        $extension = '[a-zA-Z0-9]{1,10}';

        return preg_match('/^[1-9][0-9]*\/(?:'.$ulid.'\.'.$extension.'|source-pexels-[1-9][0-9]*-'.$ulid.'\.(?:jpg|png|webp))$/iD', $path) === 1;
    }

    /** @return list<array{0: string, 1: string}> */
    private function quarantinedFilePaths(string $root): array
    {
        $quarantineRoot = $root.DIRECTORY_SEPARATOR.'.cm-orphan-quarantine';
        if (! is_dir($quarantineRoot) || is_link($quarantineRoot)) {
            return [];
        }

        $files = [];
        foreach (Finder::create()->files()->in($quarantineRoot)->ignoreDotFiles(false)->ignoreVCS(true) as $file) {
            $absolutePath = $file->getPathname();
            if (is_link($absolutePath)) {
                continue;
            }

            $realPath = realpath($absolutePath);
            if ($realPath === false || ! str_starts_with($realPath, $quarantineRoot.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($quarantineRoot) + 1));
            $separator = strpos($relativePath, '/');
            if ($separator === false) {
                continue;
            }

            $quarantineAt = substr($relativePath, 0, $separator);
            $originalPath = substr($relativePath, $separator + 1);
            if (preg_match('/^[0-9]{14}$/D', $quarantineAt) === 1 && $this->isManagedPath($originalPath)) {
                $files[] = [$quarantineAt, $originalPath];
            }
        }

        sort($files, SORT_REGULAR);

        return $files;
    }

    /** @phpstan-impure */
    private function isReferenced(string $path): bool
    {
        return MediaAsset::query()
            ->where('disk', 'scratchpad')
            ->where('path', $path)
            ->exists();
    }

    private function fileModifiedAt(string $path): int|false
    {
        return @filemtime($path);
    }
}
