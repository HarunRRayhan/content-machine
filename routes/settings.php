<?php

use App\Http\Controllers\Settings\PostsyncerSettingsController;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', SetCurrentWorkspace::class])
    ->prefix('settings')
    ->name('settings.')
    ->group(function () {
        Route::redirect('/', '/settings/postsyncer')->name('index');

        Route::get('postsyncer', [PostsyncerSettingsController::class, 'edit'])->name('postsyncer.edit');
        Route::put('postsyncer', [PostsyncerSettingsController::class, 'update'])->name('postsyncer.update');
        Route::post('postsyncer/refresh-accounts', [PostsyncerSettingsController::class, 'refreshAccounts'])->name('postsyncer.refresh-accounts');
    });
