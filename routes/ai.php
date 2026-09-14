<?php

use App\Http\Middleware\AuthenticateGateway;
use App\Mcp\Servers\UnifiedServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware('throttle:60,1')->group(fn () => Mcp::oauthRoutes());
Mcp::web('/mcp', UnifiedServer::class)->middleware([AuthenticateGateway::class, 'throttle:120,1']);
