<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PostSyncer write throttle
    |--------------------------------------------------------------------------
    |
    | Every PostSyncer write (media upload, post create, post update) is spaced
    | at least `min_interval_seconds` apart across all Content Machine processes
    | and never overlaps another write. A write that cannot get its slot within
    | `max_wait_seconds` fails before any request is sent, so it is retried
    | without being treated as an unknown outcome. The effective wait is capped
    | at PostsyncerWriteThrottle::MAX_WAIT_CEILING (60 seconds) and at the queue
    | job's remaining budget.
    |
    | Set POSTSYNCER_MIN_CREATE_INTERVAL=0 or POSTSYNCER_WRITE_THROTTLE_ENABLED=false
    | to disable throttling.
    |
    */

    'write_throttle' => [
        'enabled' => filter_var(env('POSTSYNCER_WRITE_THROTTLE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'min_interval_seconds' => (int) env('POSTSYNCER_MIN_CREATE_INTERVAL', 20),
        'max_wait_seconds' => (int) env('POSTSYNCER_WRITE_THROTTLE_MAX_WAIT', 60),
    ],

];
