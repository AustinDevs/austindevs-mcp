<?php

namespace App\Http\Middleware;

use App\Models\GatewayToken;
use App\Models\User;
use App\Services\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateGateway
{
    public function __construct(private ActivityLogger $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        $owner = User::query()->orderBy('id')->first();
        $hadBearer = is_string($request->bearerToken());
        $token = $request->bearerToken() ?: ($owner?->gateway_header ? $request->header($owner->gateway_header) : null);
        if ($owner?->gateway_token_hash && is_string($token) && hash_equals($owner->gateway_token_hash, hash('sha256', $token))) {
            Auth::setUser($owner);
            $request->setUserResolver(fn (): User => $owner);

            return $next($request);
        }
        if ($owner) {
            $tokens = GatewayToken::query()->where('user_id', $owner->id)->whereNull('revoked_at')->get();
            foreach ($tokens as $storedToken) {
                $candidate = $storedToken->header ? $request->header($storedToken->header) : $request->bearerToken();
                if (is_string($candidate) && hash_equals($storedToken->token_hash, hash('sha256', $candidate))) {
                    $storedToken->update(['last_used_at' => now()]);
                    Auth::setUser($owner);
                    $request->setUserResolver(fn (): User => $owner);

                    return $next($request);
                }
            }
        }
        $user = Auth::guard('api')->user();
        if (! $user || ! $user->isOwner()) {
            $this->reject($request, $owner, $hadBearer, 'unauthenticated');

            return response()->json(['error' => 'Unauthenticated'], 401)->header('WWW-Authenticate', 'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
        }
        if (! $user->tokenCan('mcp:use')) {
            $this->reject($request, $owner, $hadBearer, 'missing mcp:use scope');
            abort(403);
        }
        Auth::setUser($user);
        $request->setUserResolver(fn (): User => $user);

        return $next($request);
    }

    /**
     * Passport blanks the Authorization header after a failed token check, so bearer presence is captured up front.
     */
    private function reject(Request $request, ?User $owner, bool $hadBearer, string $reason): void
    {
        $this->logger->warning('auth', 'Gateway request rejected', [
            'ip' => $request->ip(), 'reason' => $reason, 'has_bearer' => $hadBearer,
            'has_custom_header' => (bool) ($owner?->gateway_header && $request->hasHeader($owner->gateway_header)),
        ]);
    }
}
