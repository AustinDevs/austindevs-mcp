<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateGateway
{
    public function handle(Request $request, Closure $next): Response
    {
        $owner = User::query()->orderBy('id')->first();
        $token = $request->bearerToken() ?: ($owner?->gateway_header ? $request->header($owner->gateway_header) : null);
        if ($owner?->gateway_token_hash && is_string($token) && hash_equals($owner->gateway_token_hash, hash('sha256', $token))) {
            Auth::setUser($owner);
            $request->setUserResolver(fn (): User => $owner);

            return $next($request);
        }
        $user = Auth::guard('api')->user();
        if (! $user || ! $user->isOwner()) {
            return response()->json(['error' => 'Unauthenticated'], 401)->header('WWW-Authenticate', 'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
        }
        abort_unless($user->tokenCan('mcp:use'), 403);
        Auth::setUser($user);
        $request->setUserResolver(fn (): User => $user);

        return $next($request);
    }
}
