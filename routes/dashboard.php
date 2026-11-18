<?php

use App\Http\Controllers\ApiTokens\WorkspaceApiTokensController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Ideas\IdeasController;
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
        // Login, register, and email-verify still land here. Other pages
        // keep "Dashboard" as their root breadcrumb.
        Route::get('/', [DashboardController::class, 'home'])->name('home');

        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team/invitations', [TeamController::class, 'storeInvitation'])->name('team.invitations.store');

        // API access lives under Team in the sidebar and the URL; tokens are
        // always scoped to the session's current workspace.
        Route::get('team/api-tokens', [WorkspaceApiTokensController::class, 'index'])->name('team.api-tokens.index');
        Route::post('team/api-tokens', [WorkspaceApiTokensController::class, 'store'])->name('team.api-tokens.store');
        Route::delete('team/api-tokens/{apiToken}', [WorkspaceApiTokensController::class, 'revoke'])->name('team.api-tokens.revoke');

        Route::get('ideas', [IdeasController::class, 'index'])->name('ideas.index');
        Route::get('ideas/{idea}', [IdeasController::class, 'show'])->name('ideas.show');
        Route::patch('ideas/{idea}', [IdeasController::class, 'update'])->name('ideas.update');
        Route::post('ideas/{idea}/drop', [IdeasController::class, 'drop'])->name('ideas.drop');
        Route::post('ideas/{idea}/promote', [IdeasController::class, 'promote'])->name('ideas.promote');

        // Posts, videos, and scratchpad are first-class at /posts, /videos,
        // and /scratchpad. These redirects keep old /dashboard/... bookmarks
        // working. Publish and scratchpad writes stay only on the new URLs,
        // not under dashboard.
        Route::permanentRedirect('posts', '/posts');
        Route::permanentRedirect('posts/{post}', '/posts/{post}');
        Route::permanentRedirect('posts/{post}/media/{mediaAsset}', '/posts/{post}/media/{mediaAsset}');
        Route::permanentRedirect('videos', '/videos');
        Route::permanentRedirect('videos/{video}', '/videos/{video}');
        Route::permanentRedirect('videos/{video}/media/{mediaAsset}', '/videos/{video}/media/{mediaAsset}');
        Route::permanentRedirect('videos/{video}/presentation', '/videos/{video}/presentation');
        Route::permanentRedirect('scratchpad', '/scratchpad');
        Route::permanentRedirect('scratchpad/media/{mediaAsset}', '/scratchpad/media/{mediaAsset}');
        Route::permanentRedirect('scratchpad/{entry}', '/scratchpad/{entry}');

        // AI Models and Telegram live under /settings now. Keep the old
        // dashboard bookmarks working the same way PostSyncer does.
        Route::permanentRedirect('ai-providers', '/settings/ai-providers');
        Route::permanentRedirect('telegram', '/settings/telegram');

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

        Route::permanentRedirect('settings/postsyncer', '/settings/postsyncer');
    });
