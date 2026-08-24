<?php

use App\Http\Controllers\Api\V1\IdeasApiController;
use App\Http\Controllers\Api\V1\ScratchpadApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — workspace-token authenticated
|--------------------------------------------------------------------------
|
| Every route below is mounted with auth.workspace-token, which resolves
| the bearer token, binds CurrentWorkspace, and (when a parameter is given)
| enforces the token ability it names: scratchpad:read / scratchpad:write /
| ideas:read / ideas:write. There is deliberately no session or CSRF here;
| the plaintext token is the credential.
|
| Entries are addressed by their public_id (ULID), ideas by human_id
| (PI-7 / VI-3) — neither leaks the internal autoincrement id as a URL
| handle.
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('scratchpad', [ScratchpadApiController::class, 'index'])
        ->middleware('auth.workspace-token:scratchpad:read')
        ->name('scratchpad.index');

    Route::post('scratchpad/text', [ScratchpadApiController::class, 'captureText'])
        ->middleware('auth.workspace-token:scratchpad:write')
        ->name('scratchpad.capture-text');

    Route::post('scratchpad/link', [ScratchpadApiController::class, 'captureLink'])
        ->middleware('auth.workspace-token:scratchpad:write')
        ->name('scratchpad.capture-link');

    Route::post('scratchpad/photo', [ScratchpadApiController::class, 'capturePhoto'])
        ->middleware('auth.workspace-token:scratchpad:write')
        ->name('scratchpad.capture-photo');

    Route::post('scratchpad/voice', [ScratchpadApiController::class, 'captureVoice'])
        ->middleware('auth.workspace-token:scratchpad:write')
        ->name('scratchpad.capture-voice');

    // Registered after the literal capture segments above so "text",
    // "link", ... are never captured as a {public_id}.
    Route::get('scratchpad/{public_id}', [ScratchpadApiController::class, 'show'])
        ->middleware('auth.workspace-token:scratchpad:read')
        ->where('public_id', '[A-Za-z0-9]+')
        ->name('scratchpad.show');

    Route::patch('scratchpad/{public_id}', [ScratchpadApiController::class, 'update'])
        ->middleware('auth.workspace-token:scratchpad:write')
        ->where('public_id', '[A-Za-z0-9]+')
        ->name('scratchpad.update');

    Route::delete('scratchpad/{public_id}', [ScratchpadApiController::class, 'destroy'])
        ->middleware('auth.workspace-token:scratchpad:write')
        ->where('public_id', '[A-Za-z0-9]+')
        ->name('scratchpad.destroy');

    Route::post('scratchpad/{public_id}/triage', [ScratchpadApiController::class, 'triage'])
        ->middleware('auth.workspace-token:scratchpad:write')
        ->where('public_id', '[A-Za-z0-9]+')
        ->name('scratchpad.triage');

    Route::get('scratchpad/{public_id}/media/{mediaAsset}', [ScratchpadApiController::class, 'media'])
        ->middleware('auth.workspace-token:scratchpad:read')
        ->where('public_id', '[A-Za-z0-9]+')
        ->name('scratchpad.media');

    Route::get('ideas', [IdeasApiController::class, 'index'])
        ->middleware('auth.workspace-token:ideas:read')
        ->name('ideas.index');

    Route::patch('ideas/{human_id}', [IdeasApiController::class, 'update'])
        ->middleware('auth.workspace-token:ideas:write')
        ->name('ideas.update');

    // After PATCH so {human_id} never swallows "update-shaped" literals.
    Route::get('ideas/{human_id}', [IdeasApiController::class, 'show'])
        ->middleware('auth.workspace-token:ideas:read')
        ->name('ideas.show');
});
