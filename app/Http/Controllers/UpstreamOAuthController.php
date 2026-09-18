<?php

namespace App\Http\Controllers;

use App\Filament\Resources\McpConnections\McpConnectionResource;
use App\Models\McpConnection;
use App\Services\ActivityLogger;
use App\Services\UpstreamOAuth;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class UpstreamOAuthController extends Controller
{
    public function __construct(private ActivityLogger $logger) {}

    public function connect(McpConnection $connection, UpstreamOAuth $oauth): RedirectResponse
    {
        abort_unless($connection->auth_type === 'oauth', 404);
        try {
            return redirect()->away($oauth->authorize($connection));
        } catch (Throwable $exception) {
            $this->logger->error('oauth', 'OAuth discovery failed: '.$exception->getMessage(), ['exception' => $exception::class], $connection);
            Notification::make()->danger()->title('OAuth discovery failed')->body($exception->getMessage())->persistent()->send();

            return redirect(McpConnectionResource::getUrl());
        }
    }

    public function callback(Request $request, UpstreamOAuth $oauth): RedirectResponse
    {
        $state = $request->query('state');
        $connectionId = is_string($state) && preg_match('/^[A-Za-z0-9]{64}$/', $state) ? $request->session()->pull('upstream-states.'.$state) : null;
        $connection = is_int($connectionId) ? McpConnection::find($connectionId) : null;
        $pending = $connection ? $request->session()->pull('upstream.'.$connection->id) : null;
        if (! $connection || ! is_array($pending) || ! hash_equals($pending['state'], $state) || $pending['expires'] < now()->timestamp) {
            $this->logger->warning('oauth', 'OAuth callback rejected', ['reason' => $connection ? 'state mismatch or expired' : 'unknown state'], $connection);
            abort(403);
        }
        $this->logger->info('oauth', 'OAuth callback received for '.$connection->name, [
            'has_code' => is_string($request->query('code')), 'error' => $request->query('error'), 'error_description' => $request->query('error_description'),
        ], $connection);
        try {
            if ($request->has('error') || ! is_string($request->query('code'))) {
                $reason = $request->query('error') ? ': '.$request->query('error').($request->query('error_description') ? ' ('.$request->query('error_description').')' : '') : '';
                throw new RuntimeException('Authorization was declined'.$reason);
            }
            $oauth->exchange($connection, $request->query('code'), $pending['verifier']);
            Notification::make()->success()->title('MCP connected')->send();
        } catch (Throwable $exception) {
            $this->logger->error('oauth', 'OAuth connection failed: '.$exception->getMessage(), ['exception' => $exception::class], $connection);
            Notification::make()->danger()->title('OAuth connection failed')->body($exception->getMessage())->persistent()->send();
        }

        return redirect(McpConnectionResource::getUrl());
    }
}
