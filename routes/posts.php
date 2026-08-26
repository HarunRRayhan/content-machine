<?php

use App\Http\Controllers\Posts\PostsController;
use App\Http\Controllers\Posts\PublishPostController;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', SetCurrentWorkspace::class])
    ->group(function () {
        Route::get('posts', [PostsController::class, 'index'])->name('posts.index');
        Route::get('posts/{post}', [PostsController::class, 'show'])->name('posts.show');
        Route::get('posts/{post}/media/{mediaAsset}', [PostsController::class, 'media'])->name('posts.media');
        Route::patch('posts/{post}', [PostsController::class, 'update'])->name('posts.update');
        Route::post('posts/{post}/publish', PublishPostController::class)->name('posts.publish');
    });
