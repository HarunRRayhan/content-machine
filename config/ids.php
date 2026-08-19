<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Human-readable ID prefixes
    |--------------------------------------------------------------------------
    |
    | Maps a content "kind" (as reserved via ReserveContentIdAction) to the
    | prefix used to build its human_id, e.g. kind "post_idea" + number 7 =>
    | "PI-7". Only "post_idea" is actually reserved by this phase; the rest
    | are placeholders for later phases (video ideas, posts, videos) so this
    | config's shape doesn't need revisiting when they land.
    |
    */

    'prefixes' => [
        'post_idea' => 'PI',
        'video_idea' => 'VI',
        'post' => 'P',
        'video' => 'V',
    ],

];
