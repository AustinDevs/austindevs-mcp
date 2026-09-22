<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class GoogleLoginController extends Controller
{
    private const OWNER_EMAIL = 'kevin@austindevs.com';

    public function redirect(): RedirectResponse
    {
        return $this->provider()->with(['login_hint' => self::OWNER_EMAIL, 'prompt' => 'select_account'])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            $request->session()->forget(['state', 'code_verifier']);
            abort(403, 'Google sign-in was declined.');
        }

        try {
            $googleUser = $this->provider()->user();
        } catch (InvalidStateException) {
            abort(403, 'Google sign-in expired or could not be verified. Please sign in again.');
        } catch (Throwable $exception) {
            app(ActivityLogger::class)->warning('auth', 'Google sign-in failed', ['exception' => $exception::class]);
            abort(503, 'Google sign-in is unavailable. Please try again.');
        }

        abort_unless(strtolower($googleUser->getEmail() ?? '') === self::OWNER_EMAIL
            && ($googleUser->user['email_verified'] ?? false) === true, 403, 'This Google account is not authorized.');

        $owner = User::query()->orderBy('id')->first();
        abort_unless($owner, 403, 'The gateway owner has not been configured.');

        Auth::guard('web')->login($owner);
        $request->session()->regenerate();

        return redirect()->intended(route('filament.app.resources.mcp-connections.index'));
    }

    private function provider(): GoogleProvider
    {
        abort_unless(config('services.google.client_id') && config('services.google.client_secret'), 503, 'Google sign-in is not configured.');

        return Socialite::driver('google')->redirectUrl(route('google.callback'))->enablePKCE();
    }
}
