<?php

use App\Http\Controllers\MediaUrlCheckController;
use App\Http\Controllers\Videos\PublishVideoController;
use App\Http\Controllers\Videos\VideoPresentationController;
use App\Http\Controllers\Videos\VideosController;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', SetCurrentWorkspace::class])
    ->group(function () {
        Route::post('media-urls/check', MediaUrlCheckController::class)->name('media-urls.check');
        Route::get('videos', [VideosController::class, 'index'])->name('videos.index');
        Route::get('videos/{video}', [VideosController::class, 'show'])->name('videos.show');
        Route::get('videos/{video}/media/{mediaAsset}', [VideosController::class, 'media'])->name('videos.media');
        Route::get('videos/{video}/presentation', [VideoPresentationController::class, 'show'])->name('videos.presentation');
        Route::patch('videos/{video}', [VideosController::class, 'update'])->name('videos.update');
        Route::post('videos/{video}/publish', PublishVideoController::class)->name('videos.publish');
    });
