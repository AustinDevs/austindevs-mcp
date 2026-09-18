<?php

use App\Models\GatewayLog;
use App\Models\McpConnection;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(LazilyRefreshDatabase::class);

test('logger records rows with level category connection and duration', function () {
    $connection = McpConnection::factory()->create();
    $log = app(ActivityLogger::class)->info('tool_call', 'Called search', ['tool_name' => 'search'], $connection, 120);
    expect($log)->toBeInstanceOf(GatewayLog::class);
    expect($log->fresh())->toMatchArray(['level' => 'info', 'category' => 'tool_call', 'message' => 'Called search', 'mcp_connection_id' => $connection->id, 'duration_ms' => 120, 'context' => ['tool_name' => 'search']]);
    expect($log->connection->is($connection))->toBeTrue();
    app(ActivityLogger::class)->warning('auth', 'Rejected');
    app(ActivityLogger::class)->error('upstream', 'Failed');
    expect(GatewayLog::orderBy('id')->pluck('level')->all())->toBe(['info', 'warning', 'error']);
    expect(GatewayLog::latest('id')->first()->context)->toBeNull();
});

test('logger redacts secret keys recursively and truncates long strings', function () {
    $context = [
        'headers' => ['Authorization' => 'Bearer abc', 'X-Account' => 'me', 'Set-Cookie' => 'x'],
        'access_token' => 'tok', 'nested' => ['client_secret' => 's', 'code_verifier' => 'c', 'ok' => 'fine'],
        'body' => str_repeat('a', 2500), 'has_code' => true, 'error_code' => 'invalid_grant',
    ];
    $redacted = app(ActivityLogger::class)->redact($context);
    expect($redacted['headers'])->toBe(['Authorization' => '[redacted]', 'X-Account' => 'me', 'Set-Cookie' => '[redacted]']);
    expect($redacted['access_token'])->toBe('[redacted]');
    expect($redacted['nested'])->toBe(['client_secret' => '[redacted]', 'code_verifier' => '[redacted]', 'ok' => 'fine']);
    expect($redacted['body'])->toEndWith('… [truncated]')->and(strlen($redacted['body']))->toBeLessThan(2100);
    expect($redacted['has_code'])->toBeTrue()->and($redacted['error_code'])->toBe('invalid_grant');
    $log = app(ActivityLogger::class)->error('oauth', str_repeat('m', 300), $context);
    expect(strlen($log->fresh()->message))->toBe(255);
    expect($log->fresh()->context['access_token'])->toBe('[redacted]');
});

test('logger scrubs tokens embedded in free text', function () {
    $body = '{"access_token":"abc123def456","token_type":"bearer","note":"Authorization: Bearer eyJhbGciOi.payload.sig","status":"ok"}';
    $scrubbed = app(ActivityLogger::class)->redact(['body' => $body, 'query' => 'client_secret=s3cr3tvalue&scope=read'])['body'];
    expect($scrubbed)->not->toContain('abc123def456', 'eyJhbGciOi')->toContain('"token_type":"bearer"', '"status":"ok"');
    expect(app(ActivityLogger::class)->redact(['query' => 'client_secret=s3cr3tvalue&scope=read'])['query'])->toBe('client_secret=[redacted]&scope=read');
});

test('logger never throws when the table is unavailable', function () {
    Log::spy();
    DB::statement('DROP TABLE gateway_logs');
    expect(app(ActivityLogger::class)->info('auth', 'still fine'))->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});

test('logs older than thirty days are prunable', function () {
    GatewayLog::factory()->create(['created_at' => now()->subDays(31)]);
    $recent = GatewayLog::factory()->create(['created_at' => now()->subDays(29)]);
    $this->artisan('model:prune', ['--model' => [GatewayLog::class]])->assertSuccessful();
    expect(GatewayLog::pluck('id')->all())->toBe([$recent->id]);
});
