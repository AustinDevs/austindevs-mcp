<?php

use App\Http\Controllers\GoogleLoginController;
use App\Http\Controllers\UpstreamOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/app', fn () => redirect()->route('filament.app.resources.mcp-connections.index'));
Route::get('/sign-in', fn () => redirect()->route('filament.app.auth.login'))->name('login');
Route::get('/auth/google/callback', [GoogleLoginController::class, 'callback'])->middleware(['guest', 'throttle:20,1'])->name('google.callback');
Route::middleware(['auth', 'can:manage-gateway'])->group(function (): void {
    Route::post('/connections/{connection}/connect', [UpstreamOAuthController::class, 'connect'])->name('upstream.connect');
    Route::get('/connections/oauth/callback', [UpstreamOAuthController::class, 'callback'])->name('upstream.callback');
});
