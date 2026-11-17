<?php

namespace App\Data\Socialreins;

/** What a move off PostSyncer found, or did, for one post or video. Pairs are `language:platform`. */
final readonly class MoveOffPostsyncerResult
{
    /**
     * @param  list<string>  $postsyncer
     * @param  list<string>  $move
     * @param  list<string>  $leftOut
     */
    public function __construct(
        public string $humanId,
        public ?string $scheduledAt,
        public array $postsyncer,
        public array $move,
        public array $leftOut,
        public string $outcome,
    ) {}
}
