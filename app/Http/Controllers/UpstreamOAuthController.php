<?php

namespace App\Http\Controllers;

use App\Filament\Resources\McpConnections\McpConnectionResource;
use App\Models\McpConnection;
use App\Services\UpstreamOAuth;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class UpstreamOAuthController extends Controller
{
    public function connect(McpConnection $connection, UpstreamOAuth $oauth): RedirectResponse
    {
        abort_unless($connection->auth_type === 'oauth', 404);
        try {
            return redirect()->away($oauth->authorize($connection));
        } catch (Throwable) {
            Notification::make()->danger()->title('OAuth discovery failed')->body('Check the MCP URL and OAuth credentials, then try again.')->send();

            return redirect(McpConnectionResource::getUrl());
        }
    }

    public function callback(Request $request, UpstreamOAuth $oauth): RedirectResponse
    {
        $state = $request->query('state');
        abort_unless(is_string($state) && preg_match('/^[A-Za-z0-9]{64}$/', $state), 403);
        $connectionId = $request->session()->pull('upstream-states.'.$state);
        abort_unless(is_int($connectionId), 403);
        $connection = McpConnection::findOrFail($connectionId);
        $pending = $request->session()->pull('upstream.'.$connection->id);
        abort_unless(is_array($pending) && is_string($request->query('state')) && hash_equals($pending['state'], $request->query('state')) && $pending['expires'] >= now()->timestamp, 403);
        try {
            if ($request->has('error') || ! is_string($request->query('code'))) {
                throw new \RuntimeException('Authorization was declined.');
            }
            $oauth->exchange($connection, $request->query('code'), $pending['verifier']);
            Notification::make()->success()->title('MCP connected')->send();
        } catch (Throwable) {
            Notification::make()->danger()->title('OAuth connection failed')->body('Reconnect to authorize this server again.')->send();
        }

        return redirect(McpConnectionResource::getUrl());
    }
}
