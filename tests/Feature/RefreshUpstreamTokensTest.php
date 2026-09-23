<?php

use App\Models\McpConnection;
use App\Services\UpstreamOAuth;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

function scheduledOAuthConnection(array $credentials = [], array $attributes = []): McpConnection
{
    return McpConnection::factory()->create([...[
        'auth_type' => 'oauth', 'status' => 'Connected',
        'credentials' => [...[
            'client_id' => 'client', 'access_token' => 'access', 'refresh_token' => 'refresh',
            'expires_at' => now()->addMinutes(4)->timestamp,
            'last_token_refresh_at' => now()->subHour()->timestamp,
            'metadata' => ['token_endpoint' => 'https://auth.example.com/token'],
        ], ...$credentials],
    ], ...$attributes]);
}

test('scheduled refresh renews nearly expired access without MCP traffic and retains the refresh token', function () {
    $this->freezeTime();
    $connection = scheduledOAuthConnection(['refresh_token_expires_at' => now()->addDays(5)->timestamp]);
    Http::preventStrayRequests();
    Http::fake(['https://auth.example.com/token' => Http::response(['access_token' => 'renewed', 'expires_in' => 3600])]);

    $this->artisan('oauth:refresh-upstream')->assertSuccessful();
    $this->artisan('oauth:refresh-upstream')->assertSuccessful();

    expect($connection->fresh()->credentials)->toMatchArray([
        'access_token' => 'renewed', 'refresh_token' => 'refresh', 'expires_at' => now()->addHour()->timestamp,
        'last_token_refresh_at' => now()->timestamp, 'refresh_token_expires_at' => now()->addDays(5)->timestamp,
    ]);
    Http::assertSentCount(1);
});

test('scheduled refresh keeps idle tokens alive even without an access expiry', function (?int $expiresIn) {
    $this->freezeTime();
    $connection = scheduledOAuthConnection([
        'expires_at' => $expiresIn ? now()->addDays($expiresIn)->timestamp : null,
        'last_token_refresh_at' => now()->subDays(7)->timestamp,
    ]);
    Http::preventStrayRequests();
    Http::fake(['https://auth.example.com/token' => Http::response(['access_token' => 'renewed'])]);

    $this->artisan('oauth:refresh-upstream')->assertSuccessful();

    expect($connection->fresh()->credentials['last_token_refresh_at'])->toBe(now()->timestamp);
    Http::assertSentCount(1);
})->with(['unknown access expiry' => null, 'long lived access' => 30]);

test('scheduled refresh skips disabled, non OAuth, unrefreshable and recently refreshed connections', function () {
    $this->freezeTime();
    scheduledOAuthConnection([], ['enabled' => false]);
    scheduledOAuthConnection([], ['auth_type' => 'bearer']);
    scheduledOAuthConnection(['refresh_token' => null]);
    scheduledOAuthConnection([], ['status' => 'Reconnect required']);
    scheduledOAuthConnection(['expires_at' => now()->addHour()->timestamp]);
    Http::preventStrayRequests();
    Http::fake(['https://auth.example.com/token' => Http::response([])]);

    $this->artisan('oauth:refresh-upstream')->assertSuccessful();

    Http::assertNothingSent();
});

test('refresh retries transient failures and continues other connections', function () {
    $this->freezeTime();
    $failed = scheduledOAuthConnection();
    $healthy = scheduledOAuthConnection();
    Http::preventStrayRequests();
    Http::fake(['https://auth.example.com/token' => Http::sequence()->push(['error' => 'temporarily_unavailable'], 503)->push(['access_token' => 'healthy', 'expires_in' => 3600])->push(['access_token' => 'recovered', 'expires_in' => 3600])]);

    $this->artisan('oauth:refresh-upstream')->assertFailed();

    expect($failed->fresh()->status)->toBe('Connected');
    expect($healthy->fresh()->credentials['access_token'])->toBe('healthy');

    $this->artisan('oauth:refresh-upstream')->assertSuccessful();

    expect($failed->fresh()->credentials['access_token'])->toBe('recovered');
    Http::assertSentCount(3);
});

test('revoked tokens require reconnection and are not retried by the scheduler', function () {
    $this->freezeTime();
    $connection = scheduledOAuthConnection();
    Http::preventStrayRequests();
    Http::fake(['https://auth.example.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->artisan('oauth:refresh-upstream')->assertFailed();
    $this->artisan('oauth:refresh-upstream')->assertSuccessful();

    expect($connection->fresh()->status)->toBe('Reconnect required');
    Http::assertSentCount(1);
});

test('provider refresh expiry is tracked and replaced only for a new token or disclosed lifetime', function () {
    $this->freezeTime();
    $connection = scheduledOAuthConnection();
    Http::preventStrayRequests();
    Http::fake(['https://auth.example.com/token' => Http::sequence()
        ->push(['access_token' => 'one', 'refresh_token' => 'rotated', 'refresh_token_expires_in' => 86400, 'expires_in' => 3600])
        ->push(['access_token' => 'two', 'refresh_token' => 'new-with-unknown-expiry', 'expires_in' => 3600])]);

    $this->artisan('oauth:refresh-upstream')->assertSuccessful();

    expect($connection->fresh()->credentials['refresh_token_expires_at'])->toBe(now()->addDay()->timestamp);
    $this->travel(1)->hours();
    $this->artisan('oauth:refresh-upstream')->assertSuccessful();
    expect($connection->fresh()->credentials)->not->toHaveKey('refresh_token_expires_at');
    Http::assertSentCount(2);
});

test('refresh rechecks the stored token after acquiring the lock', function () {
    $this->freezeTime();
    $stale = scheduledOAuthConnection();
    $fresh = $stale->fresh();
    $fresh->update(['credentials' => [...$fresh->credentials, 'expires_at' => now()->addHour()->timestamp, 'last_token_refresh_at' => now()->timestamp]]);
    Http::preventStrayRequests();
    Http::fake(['https://auth.example.com/token' => Http::response([])]);

    app(UpstreamOAuth::class)->refresh($stale, scheduled: true);

    Http::assertNothingSent();
});

test('a nearly expired refresh token is attempted even when the access token remains valid', function () {
    $this->freezeTime();
    $connection = scheduledOAuthConnection([
        'expires_at' => now()->addDays(5)->timestamp,
        'refresh_token_expires_at' => now()->addHours(12)->timestamp,
    ]);
    Http::preventStrayRequests();
    Http::fake(['https://auth.example.com/token' => Http::response(['access_token' => 'renewed', 'expires_in' => 3600])]);

    $this->artisan('oauth:refresh-upstream')->assertSuccessful();
    $this->artisan('oauth:refresh-upstream')->assertSuccessful();

    expect($connection->fresh()->credentials)->toMatchArray(['access_token' => 'renewed', 'refresh_token_expires_at' => now()->addHours(12)->timestamp]);
    Http::assertSentCount(1);
});

test('legacy tokens with no refresh history receive an initial keepalive', function () {
    $this->freezeTime();
    $connection = scheduledOAuthConnection(['last_token_refresh_at' => null, 'expires_at' => null]);
    Http::preventStrayRequests();
    Http::fake(['https://auth.example.com/token' => Http::response(['access_token' => 'renewed'])]);

    $this->artisan('oauth:refresh-upstream')->assertSuccessful();

    expect($connection->fresh()->credentials['last_token_refresh_at'])->toBe(now()->timestamp);
    Http::assertSentCount(1);
});
