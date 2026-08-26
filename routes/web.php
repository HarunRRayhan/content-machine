<?php

use App\Http\Controllers\Posts\PostsController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\Telegram\TelegramWebhookController;
use App\Http\Controllers\Videos\VideosController;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Short show URLs: /posts/P-50 and /videos/BV-46. A prefixed segment
// binds to human_id (see ResolvesByHumanId); a bare number still binds
// to the primary key. Same auth + workspace gate as the dashboard show.
Route::middleware(['auth', 'verified', SetCurrentWorkspace::class])->group(function () {
    Route::get('posts/{post}', [PostsController::class, 'show'])->name('posts.show');
    Route::get('videos/{video}', [VideosController::class, 'show'])->name('videos.show');
});

Route::get('invitations/{token}', [TeamInvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [TeamInvitationController::class, 'accept'])
    ->middleware('auth')
    ->name('invitations.accept');

// No auth/CSRF: Telegram itself posts here, see TelegramWebhookController's
// docblock for how a request is verified as genuinely coming from Telegram.
Route::post('telegram/webhook/{slug}', [TelegramWebhookController::class, 'handle'])->name('telegram.webhook');

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('dashboard.security.edit'),
        'manage' => route('dashboard.security.edit'),
    ]);
})->name('well-known.passkeys');
