<?php

use App\Http\Controllers\Mcp\McpController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->group(function (): void {
    Route::options('/mcp', [McpController::class, 'preflight'])->name('mcp.preflight');
    Route::post('/mcp', [McpController::class, 'handle'])
        ->middleware('auth.workspace-token')
        ->name('mcp.handle');
});
