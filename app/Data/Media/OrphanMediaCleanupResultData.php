<?php

namespace App\Data\Media;

final readonly class OrphanMediaCleanupResultData
{
    /**
     * @param  list<array{path: string, status: string, reason: string}>  $files
     */
    public function __construct(
        public array $files,
        public int $deleted,
        public int $skipped,
    ) {}
}
