<?php

use App\Http\Controllers\AiProviders\AiProviderCredentialModelsController;
use App\Http\Controllers\AiProviders\AiProviderCredentialsController;
use App\Http\Controllers\ApiTokens\WorkspaceApiTokensController;
use App\Http\Controllers\Ideas\IdeasController;
use App\Http\Controllers\Posts\PostsController;
use App\Http\Controllers\Posts\PublishPostController;
use App\Http\Controllers\Scratchpad\ScratchpadController;
use App\Http\Controllers\Settings\PostsyncerSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\Telegram\TelegramBotConfigController;
use App\Http\Controllers\Telegram\TelegramBotLinkController;
use App\Http\Controllers\Videos\PublishVideoController;
use App\Http\Controllers\Videos\VideoPresentationController;
use App\Http\Controllers\Videos\VideosController;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', SetCurrentWorkspace::class])
    ->prefix('dashboard')
    ->name('dashboard.')
    ->group(function () {
        // Scratch Pad is the app's landing page; there is no separate
        // dashboard-home view. This route stays named "home" (not removed)
        // because it's still the target every login/register/email-verify
        // redirect resolves to, and every page's root breadcrumb links here.
        Route::redirect('/', '/dashboard/scratchpad')->name('home');

        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team/invitations', [TeamController::class, 'storeInvitation'])->name('team.invitations.store');

        // API access lives under Team in the sidebar and the URL; tokens are
        // always scoped to the session's current workspace.
        Route::get('team/api-tokens', [WorkspaceApiTokensController::class, 'index'])->name('team.api-tokens.index');
        Route::post('team/api-tokens', [WorkspaceApiTokensController::class, 'store'])->name('team.api-tokens.store');
        Route::delete('team/api-tokens/{apiToken}', [WorkspaceApiTokensController::class, 'revoke'])->name('team.api-tokens.revoke');

        Route::get('scratchpad', [ScratchpadController::class, 'index'])->name('scratchpad.index');
        Route::post('scratchpad', [ScratchpadController::class, 'store'])->name('scratchpad.store');
        Route::post('scratchpad/photo', [ScratchpadController::class, 'storePhoto'])->name('scratchpad.photo');
        Route::post('scratchpad/voice', [ScratchpadController::class, 'storeVoice'])->name('scratchpad.voice');
        Route::post('scratchpad/link', [ScratchpadController::class, 'storeLink'])->name('scratchpad.link');
        Route::get('scratchpad/media/{mediaAsset}', [ScratchpadController::class, 'media'])->name('scratchpad.media');
        Route::get('scratchpad/{entry}', [ScratchpadController::class, 'show'])->name('scratchpad.show');
        Route::delete('scratchpad/{entry}', [ScratchpadController::class, 'destroy'])->name('scratchpad.destroy');
        Route::post('scratchpad/{entry}/triage', [ScratchpadController::class, 'triage'])->name('scratchpad.triage');
        Route::post('scratchpad/{entry}/suggest-triage', [ScratchpadController::class, 'suggestTriage'])->name('scratchpad.suggest-triage');

        Route::get('ideas', [IdeasController::class, 'index'])->name('ideas.index');
        Route::get('ideas/{idea}', [IdeasController::class, 'show'])->name('ideas.show');
        Route::patch('ideas/{idea}', [IdeasController::class, 'update'])->name('ideas.update');
        Route::post('ideas/{idea}/drop', [IdeasController::class, 'drop'])->name('ideas.drop');
        Route::post('ideas/{idea}/promote', [IdeasController::class, 'promote'])->name('ideas.promote');

        Route::get('posts', [PostsController::class, 'index'])->name('posts.index');
        Route::get('posts/{post}', [PostsController::class, 'show'])->name('posts.show');
        Route::get('posts/{post}/media/{mediaAsset}', [PostsController::class, 'media'])->name('posts.media');
        Route::patch('posts/{post}', [PostsController::class, 'update'])->name('posts.update');
        Route::post('posts/{post}/publish', PublishPostController::class)->name('posts.publish');

        Route::get('videos', [VideosController::class, 'index'])->name('videos.index');
        Route::get('videos/{video}', [VideosController::class, 'show'])->name('videos.show');
        Route::get('videos/{video}/presentation', [VideoPresentationController::class, 'show'])->name('videos.presentation');
        Route::patch('videos/{video}', [VideosController::class, 'update'])->name('videos.update');
        Route::post('videos/{video}/publish', PublishVideoController::class)->name('videos.publish');

        Route::get('ai-providers', [AiProviderCredentialsController::class, 'index'])->name('ai-providers.index');
        Route::post('ai-providers', [AiProviderCredentialsController::class, 'store'])->name('ai-providers.store');
        // Registered before the {aiProviderCredential} routes below so
        // "reorder" is never captured as a route-model-binding id.
        Route::post('ai-providers/reorder', [AiProviderCredentialsController::class, 'reorder'])->name('ai-providers.reorder');
        Route::patch('ai-providers/{aiProviderCredential}', [AiProviderCredentialsController::class, 'update'])->name('ai-providers.update');
        Route::delete('ai-providers/{aiProviderCredential}', [AiProviderCredentialsController::class, 'destroy'])->name('ai-providers.destroy');
        Route::post('ai-providers/{aiProviderCredential}/toggle', [AiProviderCredentialsController::class, 'toggle'])->name('ai-providers.toggle');
        Route::post('ai-providers/{aiProviderCredential}/verify', [AiProviderCredentialsController::class, 'verify'])->name('ai-providers.verify');

        Route::post('ai-provider-models/reorder', [AiProviderCredentialModelsController::class, 'reorder'])->name('ai-provider-models.reorder');
        Route::post('ai-providers/{aiProviderCredential}/models', [AiProviderCredentialModelsController::class, 'store'])->name('ai-provider-models.store');
        Route::delete('ai-provider-models/{aiProviderCredentialModel}', [AiProviderCredentialModelsController::class, 'destroy'])->name('ai-provider-models.destroy');

        Route::get('telegram', [TelegramBotConfigController::class, 'edit'])->name('telegram.edit');
        Route::post('telegram', [TelegramBotConfigController::class, 'update'])->name('telegram.update');
        Route::delete('telegram', [TelegramBotConfigController::class, 'destroy'])->name('telegram.destroy');
        Route::post('telegram/ai-chat/toggle', [TelegramBotConfigController::class, 'toggleAiChat'])->name('telegram.ai-chat.toggle');
        Route::post('telegram/link-code', [TelegramBotLinkController::class, 'store'])->name('telegram.link-code');
        Route::post('telegram/test', [TelegramBotLinkController::class, 'test'])->name('telegram.test');

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

        Route::get('settings/postsyncer', [PostsyncerSettingsController::class, 'edit'])->name('postsyncer.edit');
        Route::put('settings/postsyncer', [PostsyncerSettingsController::class, 'update'])->name('postsyncer.update');
        Route::post('settings/postsyncer/refresh-accounts', [PostsyncerSettingsController::class, 'refreshAccounts'])->name('postsyncer.refresh-accounts');
    });
