<?php

use App\Http\Controllers\Settings\GeneralSettingsController;
use App\Http\Controllers\Settings\PostsyncerSettingsController;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', SetCurrentWorkspace::class])
    ->prefix('settings')
    ->name('settings.')
    ->group(function () {
        Route::redirect('/', '/settings/general')->name('index');

        Route::get('general', [GeneralSettingsController::class, 'edit'])->name('general.edit');

        Route::get('postsyncer', [PostsyncerSettingsController::class, 'edit'])->name('postsyncer.edit');
        Route::get('postsyncer/workspaces', [PostsyncerSettingsController::class, 'workspaces'])->name('postsyncer.workspaces');
        Route::put('postsyncer', [PostsyncerSettingsController::class, 'update'])->name('postsyncer.update');
        Route::post('postsyncer/refresh-accounts', [PostsyncerSettingsController::class, 'refreshAccounts'])->name('postsyncer.refresh-accounts');

        Route::permanentRedirect('postsyncer/connecting', '/settings/postsyncer');
        Route::permanentRedirect('postsyncer/bangla', '/settings/postsyncer/workspaces');
        Route::permanentRedirect('postsyncer/english', '/settings/postsyncer/workspaces');
    });
