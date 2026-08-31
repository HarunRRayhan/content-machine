<?php

use App\Http\Controllers\Media\MediaLibraryController;
use App\Http\Controllers\Media\PostDesignTemplatesController;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', SetCurrentWorkspace::class])
    ->group(function () {
        Route::redirect('media', '/media/images')->name('media.index');

        Route::get('media/images', [MediaLibraryController::class, 'images'])->name('media.images');
        Route::get('media/videos', [MediaLibraryController::class, 'videos'])->name('media.videos');
        Route::get('media/gifs', [MediaLibraryController::class, 'gifs'])->name('media.gifs');
        Route::get('media/templates', [PostDesignTemplatesController::class, 'index'])->name('media.templates');
        Route::get('media/templates/{letter}', [PostDesignTemplatesController::class, 'show'])
            ->where('letter', '[A-Fa-f]')
            ->name('media.templates.show');

        Route::post('media', [MediaLibraryController::class, 'store'])->name('media.store');

        Route::get('media/{mediaAsset:public_id}/file', [MediaLibraryController::class, 'file'])
            ->name('media.file');
        Route::get('media/{mediaAsset:public_id}', [MediaLibraryController::class, 'show'])
            ->name('media.show');
        Route::patch('media/{mediaAsset:public_id}', [MediaLibraryController::class, 'update'])
            ->name('media.update');
        Route::delete('media/{mediaAsset:public_id}', [MediaLibraryController::class, 'destroy'])
            ->name('media.destroy');
    });
