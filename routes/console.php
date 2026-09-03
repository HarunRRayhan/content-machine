<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('postsyncer:sync-scheduled')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

Schedule::command('telegram:dispatch-pending-updates')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('telegram:dispatch-pending-outbound-messages')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('telegram:dispatch-pending-post-work')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('telegram:recover-connection-operations')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('cm:requeue-legacy-media-jobs')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('postsyncer:dispatch-pending-publishes')
    ->everyMinute()
    ->withoutOverlapping(2);
