<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Ideas\IdeasController;
use App\Http\Controllers\Scratchpad\ScratchpadController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\TeamController;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', SetCurrentWorkspace::class])
    ->prefix('dashboard')
    ->name('dashboard.')
    ->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('home');

        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team/invitations', [TeamController::class, 'storeInvitation'])->name('team.invitations.store');

        Route::get('scratchpad', [ScratchpadController::class, 'index'])->name('scratchpad.index');
        Route::post('scratchpad', [ScratchpadController::class, 'store'])->name('scratchpad.store');
        Route::get('scratchpad/{entry}', [ScratchpadController::class, 'show'])->name('scratchpad.show');
        Route::post('scratchpad/{entry}/triage', [ScratchpadController::class, 'triage'])->name('scratchpad.triage');

        Route::get('ideas', [IdeasController::class, 'index'])->name('ideas.index');
        Route::get('ideas/{idea}', [IdeasController::class, 'show'])->name('ideas.show');
        Route::patch('ideas/{idea}', [IdeasController::class, 'update'])->name('ideas.update');
        Route::post('ideas/{idea}/drop', [IdeasController::class, 'drop'])->name('ideas.drop');

        Route::redirect('settings', '/dashboard/settings/profile');

        Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        Route::get('settings/security', [SecurityController::class, 'edit'])
            ->middleware(RequirePassword::class)
            ->name('security.edit');

        Route::put('settings/password', [SecurityController::class, 'update'])
            ->middleware('throttle:6,1')
            ->name('user-password.update');

        Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
    });
