<?php

use App\Http\Controllers\UpstreamOAuthController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');
Route::get('/login', fn () => redirect()->route('filament.app.auth.login'))->name('login');
Route::middleware(['auth', 'can:manage-gateway'])->group(function (): void {
    Route::post('/connections/{connection}/connect', [UpstreamOAuthController::class, 'connect'])->name('upstream.connect');
    Route::get('/connections/oauth/callback', [UpstreamOAuthController::class, 'callback'])->name('upstream.callback');
});
