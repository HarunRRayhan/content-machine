<?php

use App\Http\Controllers\TeamInvitationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('invitations/{token}', [TeamInvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [TeamInvitationController::class, 'accept'])
    ->middleware('auth')
    ->name('invitations.accept');

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('dashboard.security.edit'),
        'manage' => route('dashboard.security.edit'),
    ]);
})->name('well-known.passkeys');
