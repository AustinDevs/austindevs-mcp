<?php

use App\Models\GatewayLog;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function logsOwner(): void
{
    $owner = User::factory()->create();
    $owner->forceFill(['gateway_token_hash' => hash('sha256', 'test-token')])->save();
}

function fetchLogs(array $arguments = []): array
{
    $response = test()->withToken('test-token')->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'gateway_logs', 'arguments' => (object) $arguments]]);
    $response->assertOk();

    return ['isError' => $response->json('result.isError'), 'body' => json_decode($response->json('result.content.0.text'), true), 'text' => $response->json('result.content.0.text')];
}

test('the log tool is always listed and returns recent rows newest first', function () {
    logsOwner();
    $this->withToken('test-token')->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])
        ->assertOk()->assertJsonPath('result.tools.0.name', 'gateway_logs');
    $connection = McpConnection::factory()->create(['name' => 'Sentry']);
    $older = GatewayLog::factory()->create(['message' => 'older', 'created_at' => now()->subMinute(), 'mcp_connection_id' => $connection->id, 'context' => ['status' => 500], 'duration_ms' => 12]);
    $newer = GatewayLog::factory()->create(['message' => 'newer', 'created_at' => now()]);
    $result = fetchLogs();
    expect($result['isError'])->toBeFalse();
    expect(array_column($result['body']['logs'], 'message'))->toBe(['newer', 'older']);
    expect($result['body']['logs'][1])->toMatchArray(['id' => $older->id, 'level' => 'info', 'category' => 'tool_call', 'connection' => 'Sentry', 'duration_ms' => 12, 'context' => ['status' => 500]]);
    expect($result['body']['logs'][0]['connection'])->toBeNull();
    expect($result['body']['logs'][0]['created_at'])->toBe($newer->created_at->toIso8601String());
});

test('the log tool filters by level category connection since and search and caps the limit', function () {
    logsOwner();
    $sentry = McpConnection::factory()->create(['name' => 'Sentry']);
    GatewayLog::factory()->create(['level' => 'error', 'category' => 'upstream', 'message' => 'Upstream returned HTTP 500', 'mcp_connection_id' => $sentry->id]);
    GatewayLog::factory()->create(['level' => 'info', 'category' => 'oauth', 'message' => 'Discovered OAuth server', 'created_at' => now()->subHours(3)]);
    GatewayLog::factory()->create(['level' => 'warning', 'category' => 'auth', 'message' => 'Gateway request rejected']);
    expect(array_column(fetchLogs(['level' => 'error'])['body']['logs'], 'message'))->toBe(['Upstream returned HTTP 500']);
    expect(array_column(fetchLogs(['category' => 'oauth'])['body']['logs'], 'message'))->toBe(['Discovered OAuth server']);
    expect(array_column(fetchLogs(['connection' => 'sent'])['body']['logs'], 'message'))->toBe(['Upstream returned HTTP 500']);
    expect(fetchLogs(['since' => '1 hour ago'])['body']['logs'])->toHaveCount(2);
    expect(fetchLogs(['since' => now()->subDay()->toIso8601String()])['body']['logs'])->toHaveCount(3);
    expect(array_column(fetchLogs(['search' => 'rejected'])['body']['logs'], 'message'))->toBe(['Gateway request rejected']);
    expect(fetchLogs(['limit' => 1])['body']['logs'])->toHaveCount(1);
    expect(fetchLogs(['limit' => 500])['isError'])->toBeTrue();
    $invalid = fetchLogs(['since' => 'not a date']);
    expect($invalid['isError'])->toBeTrue()->and($invalid['text'])->toContain('since');
});
