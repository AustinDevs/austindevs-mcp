<?php

use App\Models\GatewayLog;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

function loggingOwner(): User
{
    $owner = User::factory()->create();
    $owner->forceFill(['gateway_token_hash' => hash('sha256', 'test-token')])->save();

    return $owner;
}

function loggingCall(McpConnection $connection, string $tool = 'search'): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $connection->toolName(), 'arguments' => ['tool_name' => $tool, 'arguments' => '{"query":"hello"}']]];
}

test('successful tool calls are logged with duration and sizes', function () {
    loggingOwner();
    $connection = McpConnection::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['https://mcp.example.com/mcp' => function ($request) {
        if ($request['method'] === 'notifications/initialized') {
            return Http::response('', 202);
        }
        $body = $request['method'] === 'initialize' ? ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'serverInfo' => ['name' => 'Test', 'version' => '1']] : ['content' => [['type' => 'text', 'text' => 'Found it']]];

        return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $body]);
    }]);
    $this->withToken('test-token')->postJson('/mcp', loggingCall($connection))->assertJsonPath('result.isError', false);
    $log = GatewayLog::sole();
    expect($log)->toMatchArray(['level' => 'info', 'category' => 'tool_call', 'mcp_connection_id' => $connection->id]);
    expect($log->message)->toBe('Called search on '.$connection->name);
    expect($log->context)->toMatchArray(['tool_name' => 'search', 'argument_bytes' => 17, 'is_error' => false])->toHaveKey('result_bytes');
    expect($log->duration_ms)->toBeInt();
});

test('upstream HTTP failures log status and truncated body while the client sees a generic error', function () {
    loggingOwner();
    $connection = McpConnection::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['https://mcp.example.com/mcp' => Http::response(str_repeat('x', 3000), 500)]);
    $this->withToken('test-token')->postJson('/mcp', loggingCall($connection))
        ->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', 'Upstream connection failed. Check this server in the dashboard; reconnect if authentication expired.');
    $upstream = GatewayLog::where('category', 'upstream')->sole();
    expect($upstream->level)->toBe('error')->and($upstream->message)->toBe('Upstream returned HTTP 500');
    expect($upstream->context)->toMatchArray(['method' => 'initialize', 'status' => 500])->and($upstream->context['body'])->toEndWith('… [truncated]');
    $call = GatewayLog::where('category', 'tool_call')->sole();
    expect($call->level)->toBe('error')->and($call->message)->toBe('Tool call failed on '.$connection->name);
    expect($call->context)->toMatchArray(['tool_name' => 'search', 'error' => 'Upstream returned HTTP 500.']);
    expect($connection->fresh()->status)->toBe('Connection needs attention');
});

test('invalid upstream responses log the JSON-RPC error', function () {
    loggingOwner();
    $connection = McpConnection::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['https://mcp.example.com/mcp' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32601, 'message' => 'Method not found']])]);
    $this->withToken('test-token')->postJson('/mcp', loggingCall($connection))->assertJsonPath('result.isError', true);
    $upstream = GatewayLog::where('category', 'upstream')->sole();
    expect($upstream->message)->toBe('Upstream returned an invalid response');
    expect($upstream->context['rpc_error'])->toBe(['code' => -32601, 'message' => 'Method not found']);
});
