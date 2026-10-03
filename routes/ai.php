<?php

use App\Http\Controllers\ObsidianUploadController;
use App\Http\Middleware\AuthenticateGateway;
use App\Mcp\Servers\UnifiedServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware('throttle:60,1')->group(fn () => Mcp::oauthRoutes());
Mcp::web('/mcp', UnifiedServer::class)->middleware([AuthenticateGateway::class, 'throttle:120,1']);
Route::match(['PUT', 'POST'], '/obsidian-upload/{token}', [ObsidianUploadController::class, 'store'])
    ->where('token', '[A-Za-z0-9_-]{20,128}')
    ->middleware('throttle:30,1')
    ->name('obsidian.upload');
