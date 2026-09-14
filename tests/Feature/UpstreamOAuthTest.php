<?php

use App\Models\McpConnection;
use App\Models\User;
use App\Services\UpstreamOAuth;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

function fakeUpstreamOAuth(): void
{
    Http::preventStrayRequests();
    Http::fake([
        'https://mcp.example.com/mcp' => Http::response([], 401, ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.example.com/metadata"']),
        'https://mcp.example.com/metadata' => Http::response(['authorization_servers' => ['https://auth.example.com'], 'scopes_supported' => ['tools:read']]),
        'https://auth.example.com/.well-known/oauth-authorization-server' => Http::response(['issuer' => 'https://auth.example.com', 'authorization_endpoint' => 'https://auth.example.com/authorize', 'token_endpoint' => 'https://auth.example.com/token', 'registration_endpoint' => 'https://auth.example.com/register', 'code_challenge_methods_supported' => ['S256']]),
        'https://auth.example.com/register' => Http::response(['client_id' => 'registered-client']),
        'https://auth.example.com/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'rotated-refresh', 'expires_in' => 3600]),
    ]);
}

test('dynamic discovery registers a client and completes a bound PKCE OAuth callback', function () {
    $this->actingAs(User::factory()->create());
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth']);
    fakeUpstreamOAuth();
    $response = $this->post(route('upstream.connect', $connection))->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['client_id' => 'registered-client', 'code_challenge_method' => 'S256', 'resource' => $connection->url, 'scope' => 'tools:read']);
    expect($query['code_challenge'])->not->toBeEmpty();
    $this->get(route('upstream.callback').'?'.http_build_query(['state' => $query['state'], 'code' => 'authorization-code']))->assertRedirect();
    expect($connection->fresh()->credentials)->toMatchArray(['access_token' => 'new-access', 'refresh_token' => 'rotated-refresh']);
    Http::assertSent(fn ($request) => $request->url() === 'https://auth.example.com/token' && $request['code'] === 'authorization-code' && $request['resource'] === $connection->url && strlen($request['code_verifier']) >= 43);
    $this->get(route('upstream.callback').'?state='.$query['state'].'&code=authorization-code')->assertForbidden();
});

test('supplied OAuth credentials skip dynamic registration', function () {
    $this->actingAs(User::factory()->create());
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth', 'credentials' => ['client_id' => 'manual-client', 'client_secret' => 'private-secret']]);
    fakeUpstreamOAuth();
    $response = $this->post(route('upstream.connect', $connection))->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['client_id'])->toBe('manual-client');
    Http::assertNotSent(fn ($request) => $request->url() === 'https://auth.example.com/register');
    $this->get(route('upstream.callback').'?state='.$query['state'].'&code=code')->assertRedirect();
    Http::assertSent(fn ($request) => $request->url() === 'https://auth.example.com/token' && $request->hasHeader('Authorization', 'Basic '.base64_encode('manual-client:private-secret')));
});

test('expired OAuth tokens refresh and persist rotating refresh tokens', function () {
    $this->freezeTime();
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth', 'credentials' => ['client_id' => 'client', 'access_token' => 'expired', 'refresh_token' => 'old-refresh', 'expires_at' => now()->subMinute()->timestamp, 'metadata' => ['token_endpoint' => 'https://auth.example.com/token']]]);
    fakeUpstreamOAuth();
    app(UpstreamOAuth::class)->refresh($connection);
    expect($connection->fresh()->credentials['refresh_token'])->toBe('rotated-refresh');
    app(UpstreamOAuth::class)->refresh($connection);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'old-refresh');
});

test('rejected refresh tokens mark the connection for reconnection without leaking upstream response', function () {
    $this->freezeTime();
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth', 'credentials' => ['client_id' => 'client', 'access_token' => 'expired', 'refresh_token' => 'old-refresh', 'expires_at' => now()->subMinute()->timestamp, 'metadata' => ['token_endpoint' => 'https://auth.example.com/token']]]);
    Http::preventStrayRequests();
    Http::fake(['https://auth.example.com/token' => Http::response(['error' => 'invalid_grant', 'secret' => 'sensitive'], 400)]);
    expect(fn () => app(UpstreamOAuth::class)->refresh($connection))->toThrow(RuntimeException::class, 'OAuth token exchange failed. Reconnect this server.');
    expect($connection->fresh()->status)->toBe('Reconnect required');
    Http::assertSentCount(1);
});
