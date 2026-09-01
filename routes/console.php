<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('postsyncer:sync-scheduled')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('telegram:dispatch-pending-updates')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('telegram:dispatch-pending-post-work')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('postsyncer:dispatch-pending-publishes')
    ->everyMinute()
    ->withoutOverlapping();
