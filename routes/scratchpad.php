<?php

use App\Http\Controllers\Scratchpad\ScratchpadController;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', SetCurrentWorkspace::class])
    ->group(function () {
        Route::get('scratchpad', [ScratchpadController::class, 'index'])->name('scratchpad.index');
        Route::post('scratchpad', [ScratchpadController::class, 'store'])->name('scratchpad.store');
        // photo/voice/link/media are registered before {entry} so those
        // segments are never captured as a route-model-binding id.
        Route::post('scratchpad/photo', [ScratchpadController::class, 'storePhoto'])->name('scratchpad.photo');
        Route::post('scratchpad/voice', [ScratchpadController::class, 'storeVoice'])->name('scratchpad.voice');
        Route::post('scratchpad/link', [ScratchpadController::class, 'storeLink'])->name('scratchpad.link');
        Route::get('scratchpad/media/{mediaAsset}', [ScratchpadController::class, 'media'])->name('scratchpad.media');
        Route::get('scratchpad/{entry}', [ScratchpadController::class, 'show'])->name('scratchpad.show');
        Route::delete('scratchpad/{entry}', [ScratchpadController::class, 'destroy'])->name('scratchpad.destroy');
        Route::post('scratchpad/{entry}/triage', [ScratchpadController::class, 'triage'])->name('scratchpad.triage');
        Route::post('scratchpad/{entry}/suggest-triage', [ScratchpadController::class, 'suggestTriage'])->name('scratchpad.suggest-triage');
    });
