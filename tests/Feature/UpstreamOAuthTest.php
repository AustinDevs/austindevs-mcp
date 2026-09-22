<?php

use App\Models\GatewayLog;
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

test('discovery accepts unquoted resource_metadata and logs the discovered server', function () {
    $this->actingAs(User::factory()->create());
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth']);
    Http::preventStrayRequests();
    Http::fake([
        'https://mcp.example.com/mcp' => Http::response([], 401, ['WWW-Authenticate' => 'Bearer resource_metadata=https://mcp.example.com/metadata']),
        'https://mcp.example.com/metadata' => Http::response(['authorization_servers' => ['https://auth.example.com/'], 'scopes_supported' => ['tools:read']]),
        'https://auth.example.com/.well-known/oauth-authorization-server' => Http::response(['issuer' => 'https://auth.example.com', 'authorization_endpoint' => 'https://auth.example.com/authorize', 'token_endpoint' => 'https://auth.example.com/token', 'registration_endpoint' => 'https://auth.example.com/register', 'code_challenge_methods_supported' => ['S256']]),
        'https://auth.example.com/register' => Http::response(['client_id' => 'registered-client']),
    ]);
    $this->post(route('upstream.connect', $connection))->assertRedirectContains('https://auth.example.com/authorize');
    $log = GatewayLog::where('category', 'oauth')->sole();
    expect($log->level)->toBe('info')->and($log->message)->toBe('Discovered OAuth server for '.$connection->name);
    expect($log->context)->toMatchArray(['resource_metadata_url' => 'https://mcp.example.com/metadata', 'issuer' => 'https://auth.example.com/', 'registration' => 'dynamic', 'scope' => 'tools:read']);
});

test('servers without protected resource metadata fall back to the MCP origin as issuer', function () {
    $this->actingAs(User::factory()->create());
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth', 'credentials' => ['client_id' => 'manual']]);
    Http::preventStrayRequests();
    Http::fake([
        'https://mcp.example.com/mcp' => Http::response([], 401, ['WWW-Authenticate' => 'Bearer realm="OAuth"']),
        'https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => Http::response('Not found', 404),
        'https://mcp.example.com/.well-known/oauth-protected-resource' => Http::response('Not found', 404),
        'https://mcp.example.com/.well-known/oauth-authorization-server' => Http::response(['issuer' => 'https://mcp.example.com', 'authorization_endpoint' => 'https://mcp.example.com/authorize', 'token_endpoint' => 'https://mcp.example.com/token', 'code_challenge_methods_supported' => ['S256']]),
    ]);
    $this->post(route('upstream.connect', $connection))->assertRedirectContains('https://mcp.example.com/authorize');
    expect(GatewayLog::where('category', 'oauth')->sole()->context)->toMatchArray(['resource_metadata_url' => null, 'issuer' => 'https://mcp.example.com', 'registration' => 'manual']);
});

test('servers without dynamic registration explain that a client ID is needed', function () {
    $this->actingAs(User::factory()->create());
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth']);
    Http::preventStrayRequests();
    Http::fake([
        'https://mcp.example.com/mcp' => Http::response([], 401, ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.example.com/metadata"']),
        'https://mcp.example.com/metadata' => Http::response(['authorization_servers' => ['https://auth.example.com']]),
        'https://auth.example.com/.well-known/oauth-authorization-server' => Http::response(['issuer' => 'https://auth.example.com', 'authorization_endpoint' => 'https://auth.example.com/authorize', 'token_endpoint' => 'https://auth.example.com/token', 'code_challenge_methods_supported' => ['S256']]),
    ]);
    $this->post(route('upstream.connect', $connection))->assertRedirect(route('filament.app.resources.mcp-connections.index'));
    $log = GatewayLog::where('level', 'error')->sole();
    expect($log->message)->toBe('OAuth discovery failed: This server does not support dynamic registration. Edit the connection and enter a client ID and secret.');
});

test('metadata failures name the URL and status', function () {
    $this->actingAs(User::factory()->create());
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth']);
    Http::preventStrayRequests();
    Http::fake([
        'https://mcp.example.com/mcp' => Http::response([], 401, ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.example.com/metadata"']),
        'https://mcp.example.com/metadata' => Http::response(['authorization_servers' => ['https://auth.example.com']]),
        'https://auth.example.com/*' => Http::response('nope', 503),
    ]);
    $this->post(route('upstream.connect', $connection))->assertRedirect();
    expect(GatewayLog::where('level', 'error')->sole()->message)->toContain('https://auth.example.com/.well-known/oauth-authorization-server', 'HTTP 503');
});

test('token exchange outcomes are logged without secrets', function () {
    $this->freezeTime();
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth', 'credentials' => ['client_id' => 'client', 'access_token' => 'expired', 'refresh_token' => 'old-refresh', 'expires_at' => now()->subMinute()->timestamp, 'metadata' => ['token_endpoint' => 'https://auth.example.com/token']]]);
    Http::preventStrayRequests();
    Http::fake(['https://auth.example.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'expired', 'secret' => 'sensitive'], 400)]);
    expect(fn () => app(UpstreamOAuth::class)->refresh($connection))->toThrow(RuntimeException::class);
    $log = GatewayLog::sole();
    expect($log->level)->toBe('error')->and($log->message)->toBe('OAuth refresh_token failed for '.$connection->name);
    expect($log->context)->toBe(['grant_type' => 'refresh_token', 'status' => 400, 'error' => 'invalid_grant', 'error_description' => 'expired']);
    expect(json_encode($log->context))->not->toContain('sensitive', 'old-refresh');
});

test('callback receipt and decline are logged and the notification carries the reason', function () {
    $this->actingAs(User::factory()->create());
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth']);
    fakeUpstreamOAuth();
    $response = $this->post(route('upstream.connect', $connection))->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    $this->get(route('upstream.callback').'?'.http_build_query(['state' => $query['state'], 'error' => 'access_denied', 'error_description' => 'User said no']))->assertRedirect();
    $messages = GatewayLog::where('category', 'oauth')->orderBy('id')->pluck('message')->all();
    expect($messages)->toContain('OAuth callback received for '.$connection->name);
    expect(end($messages))->toBe('OAuth connection failed: Authorization was declined: access_denied (User said no)');
    $this->get(route('upstream.callback').'?state='.str_repeat('b', 64).'&code=x')->assertForbidden();
    expect(GatewayLog::where('level', 'warning')->sole()->message)->toBe('OAuth callback rejected');
});

test('additional authorize parameters preserve the bound OAuth request', function () {
    $this->actingAs(User::factory()->create());
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth', 'credentials' => [
        'client_id' => 'manual', 'scope' => 'openid email profile', 'send_resource' => false,
        'authorize_params' => [
            'access_type' => 'offline', 'prompt' => 'consent select_account', 'login_hint' => 'kevin@example.com',
            'client_id' => 'wrong', 'redirect_uri' => 'https://wrong.example.com', 'state' => 'wrong',
            'code_challenge' => 'wrong', 'code_challenge_method' => 'plain', 'response_type' => 'token',
            'resource' => 'https://wrong.example.com', 'scope' => 'wrong',
        ],
    ]]);
    fakeUpstreamOAuth();

    $response = $this->post(route('upstream.connect', $connection))->assertRedirect();

    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    $session = session('upstream.'.$connection->id);
    expect($query)->toMatchArray([
        'client_id' => 'manual', 'redirect_uri' => route('upstream.callback'), 'state' => $session['state'],
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $session['verifier'], true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256', 'response_type' => 'code', 'scope' => 'openid email profile',
        'access_type' => 'offline', 'prompt' => 'consent select_account', 'login_hint' => 'kevin@example.com',
    ])->not->toHaveKey('resource');
    $this->get(route('upstream.callback').'?'.http_build_query(['state' => $query['state'], 'code' => 'code']))->assertRedirect();
    Http::assertSent(fn ($request) => $request->url() === 'https://auth.example.com/token'
        && $request['grant_type'] === 'authorization_code' && ! array_key_exists('resource', $request->data()));
});

test('Google refresh retains the refresh token and can omit the resource parameter', function (bool $sendResource) {
    $this->freezeTime();
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth', 'credentials' => [
        'client_id' => 'google-client', 'client_secret' => 'google-secret', 'send_resource' => $sendResource,
        'access_token' => 'expired', 'refresh_token' => 'google-refresh', 'expires_at' => now()->subMinute()->timestamp,
        'metadata' => ['token_endpoint' => 'https://oauth2.googleapis.com/token', 'token_endpoint_auth_methods_supported' => ['client_secret_post']],
    ]]);
    Http::preventStrayRequests();
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response([
        'access_token' => 'google-access', 'expires_in' => 3599, 'scope' => 'openid email profile', 'token_type' => 'Bearer',
    ])]);

    app(UpstreamOAuth::class)->refresh($connection);

    expect($connection->fresh()->credentials)->toMatchArray([
        'access_token' => 'google-access', 'refresh_token' => 'google-refresh', 'expires_at' => now()->addSeconds(3599)->timestamp,
    ]);
    Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'google-refresh' && $request['client_secret'] === 'google-secret'
        && ($sendResource ? $request['resource'] === $connection->url : ! array_key_exists('resource', $request->data())));
})->with(['resource enabled' => true, 'resource disabled' => false]);
