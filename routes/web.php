<?php

use App\Http\Controllers\PublishMediaController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\Telegram\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('invitations/{token}', [TeamInvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [TeamInvitationController::class, 'accept'])
    ->middleware('auth')
    ->name('invitations.accept');

// No auth/CSRF: Telegram itself posts here, see TelegramWebhookController's
// docblock for how a request is verified as genuinely coming from Telegram.
Route::post('telegram/webhook/{slug}', [TelegramWebhookController::class, 'handle'])->name('telegram.webhook');

// PostSyncer fetches these during link-upload; signature replaces bearer auth.
Route::get('publish-media/posts/{post}/{mediaAsset}', [PublishMediaController::class, 'post'])
    ->middleware('signed')
    ->name('publish-media.post');

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('dashboard.security.edit'),
        'manage' => route('dashboard.security.edit'),
    ]);
})->name('well-known.passkeys');
