<?php

use App\Http\Controllers\AiProviders\AiProviderCredentialModelsController;
use App\Http\Controllers\AiProviders\AiProviderCredentialsController;
use App\Http\Controllers\Settings\GeneralSettingsController;
use App\Http\Controllers\Settings\GoogleDriveController;
use App\Http\Controllers\Settings\PostsyncerSettingsController;
use App\Http\Controllers\Telegram\TelegramBotConfigController;
use App\Http\Controllers\Telegram\TelegramBotLinkController;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', SetCurrentWorkspace::class])
    ->prefix('settings')
    ->name('settings.')
    ->group(function () {
        Route::redirect('/', '/settings/general')->name('index');

        Route::get('general', [GeneralSettingsController::class, 'edit'])->name('general.edit');

        Route::get('google-drive', [GoogleDriveController::class, 'edit'])->name('google-drive.edit');
        Route::get('google-drive/connect', [GoogleDriveController::class, 'connect'])->name('google-drive.connect');
        Route::get('google-drive/callback', [GoogleDriveController::class, 'callback'])->name('google-drive.callback');
        Route::delete('google-drive', [GoogleDriveController::class, 'disconnect'])->name('google-drive.disconnect');
        Route::get('google-drive/files', [GoogleDriveController::class, 'files'])->name('google-drive.files');
        Route::post('google-drive/folder', [GoogleDriveController::class, 'setFolder'])->name('google-drive.folder');
        Route::post('google-drive/make-public', [GoogleDriveController::class, 'makePublic'])->name('google-drive.make-public');

        Route::get('postsyncer', [PostsyncerSettingsController::class, 'edit'])->name('postsyncer.edit');
        Route::get('postsyncer/workspaces', [PostsyncerSettingsController::class, 'workspaces'])->name('postsyncer.workspaces');
        Route::put('postsyncer', [PostsyncerSettingsController::class, 'update'])->name('postsyncer.update');
        Route::post('postsyncer/refresh-accounts', [PostsyncerSettingsController::class, 'refreshAccounts'])->name('postsyncer.refresh-accounts');

        Route::permanentRedirect('postsyncer/connecting', '/settings/postsyncer');
        Route::permanentRedirect('postsyncer/bangla', '/settings/postsyncer/workspaces');
        Route::permanentRedirect('postsyncer/english', '/settings/postsyncer/workspaces');

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
    });
