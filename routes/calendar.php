<?php

use App\Http\Controllers\Calendar\CalendarController;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', SetCurrentWorkspace::class])
    ->group(function () {
        Route::get('calendar', [CalendarController::class, 'index'])->name('calendar.index');
    });
