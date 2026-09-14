<?php

namespace App\Services;

use App\Models\McpConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class UpstreamOAuth
{
    private function http(): PendingRequest
    {
        return Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(15);
    }

    private function metadata(string $url): array
    {
        $response = $this->http()->get(RemoteUrl::validate($url));
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('OAuth metadata could not be loaded.');
        }

        return $response->json();
    }

    public function authorize(McpConnection $connection): string
    {
        $credentials = $connection->credentials ?? [];
        $origin = RemoteUrl::origin($connection->url);
        $probe = $this->http()->withHeaders($credentials['headers'] ?? [])->withHeaders(['Accept' => 'application/json, text/event-stream'])->post($connection->url, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'personal-mcp', 'version' => '1.0.0']],
        ]);
        preg_match('/resource_metadata="([^" ]+)"/', $probe->header('WWW-Authenticate'), $match);
        try {
            $resource = $this->metadata($match[1] ?? $origin.'/.well-known/oauth-protected-resource'.(parse_url($connection->url, PHP_URL_PATH) ?: ''));
        } catch (RuntimeException $exception) {
            if (isset($match[1])) {
                throw $exception;
            }
            $resource = $this->metadata($origin.'/.well-known/oauth-protected-resource');
        }
        $issuer = $resource['authorization_servers'][0] ?? throw new RuntimeException('No OAuth authorization server was advertised.');
        $issuerOrigin = RemoteUrl::origin($issuer);
        $issuerPath = rtrim(parse_url($issuer, PHP_URL_PATH) ?: '', '/');
        $metadata = null;
        foreach (array_unique([$issuerOrigin.'/.well-known/oauth-authorization-server'.$issuerPath, $issuerOrigin.'/.well-known/openid-configuration'.$issuerPath, rtrim($issuer, '/').'/.well-known/openid-configuration']) as $url) {
            try {
                $metadata = $this->metadata($url);
                break;
            } catch (RuntimeException) {
                continue;
            }
        }
        if (! $metadata || ($metadata['issuer'] ?? null) !== $issuer) {
            throw new RuntimeException('OAuth issuer metadata is missing or does not match.');
        }
        foreach (['authorization_endpoint', 'token_endpoint'] as $key) {
            RemoteUrl::validate($metadata[$key] ?? '');
        }
        if (isset($metadata['code_challenge_methods_supported']) && ! in_array('S256', $metadata['code_challenge_methods_supported'], true)) {
            throw new RuntimeException('The OAuth server must support PKCE S256.');
        }
        $redirect = route('upstream.callback');
        if (empty($credentials['client_id'])) {
            $registration = $this->http()->post(RemoteUrl::validate($metadata['registration_endpoint'] ?? ''), [
                'client_name' => config('app.name'), 'redirect_uris' => [$redirect], 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'token_endpoint_auth_method' => 'none',
            ]);
            if (! $registration->successful() || ! is_string($registration->json('client_id'))) {
                throw new RuntimeException('Dynamic registration failed. Supply a client ID and secret if required.');
            }
            $credentials['client_id'] = $registration->json('client_id');
            $credentials['client_secret'] = $registration->json('client_secret');
        }
        $credentials['metadata'] = $metadata;
        $connection->update(['credentials' => $credentials]);
        $state = Str::random(64);
        $verifier = Str::random(96);
        session()->put('upstream.'.$connection->id, ['state' => $state, 'verifier' => $verifier, 'expires' => now()->addMinutes(10)->timestamp]);
        session()->put('upstream-states.'.$state, $connection->id);
        $query = [
            'client_id' => $credentials['client_id'], 'redirect_uri' => $redirect, 'response_type' => 'code', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256', 'resource' => $connection->url,
        ];
        $scope = $credentials['scope'] ?? implode(' ', $resource['scopes_supported'] ?? []);
        if ($scope !== '') {
            $query['scope'] = $scope;
        }

        return $metadata['authorization_endpoint'].(str_contains($metadata['authorization_endpoint'], '?') ? '&' : '?').http_build_query($query);
    }

    public function exchange(McpConnection $connection, string $code, string $verifier): void
    {
        $this->token($connection, ['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => route('upstream.callback')]);
    }

    public function refresh(McpConnection $connection): void
    {
        $credentials = $connection->credentials ?? [];
        if (empty($credentials['access_token'])) {
            throw new RuntimeException('Connect this server with OAuth first.');
        }
        if (empty($credentials['expires_at']) || $credentials['expires_at'] > now()->addSeconds(30)->timestamp) {
            return;
        }
        Cache::lock('oauth-refresh-'.$connection->id, 30)->block(10, function () use ($connection): void {
            $connection->refresh();
            $credentials = $connection->credentials;
            if (($credentials['expires_at'] ?? PHP_INT_MAX) > now()->addSeconds(30)->timestamp) {
                return;
            }
            if (empty($credentials['refresh_token'])) {
                throw new RuntimeException('OAuth expired. Reconnect this server.');
            }
            $this->token($connection, ['grant_type' => 'refresh_token', 'refresh_token' => $credentials['refresh_token']]);
        });
    }

    private function token(McpConnection $connection, array $params): void
    {
        $credentials = $connection->credentials;
        $params += ['client_id' => $credentials['client_id'], 'resource' => $connection->url];
        $http = $this->http()->asForm();
        if (! empty($credentials['client_secret'])) {
            $methods = $credentials['metadata']['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
            if (in_array('client_secret_basic', $methods, true)) {
                $http = $http->withBasicAuth($credentials['client_id'], $credentials['client_secret']);
            } else {
                $params['client_secret'] = $credentials['client_secret'];
            }
        }
        $response = $http->post(RemoteUrl::validate($credentials['metadata']['token_endpoint']), $params);
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            $connection->update(['status' => 'Reconnect required']);
            throw new RuntimeException('OAuth token exchange failed. Reconnect this server.');
        }
        $credentials['access_token'] = $response->json('access_token');
        $credentials['refresh_token'] = $response->json('refresh_token') ?? ($credentials['refresh_token'] ?? null);
        $credentials['expires_at'] = $response->json('expires_in') ? now()->addSeconds((int) $response->json('expires_in'))->timestamp : null;
        $connection->update(['credentials' => $credentials, 'status' => 'Connected']);
    }
}
