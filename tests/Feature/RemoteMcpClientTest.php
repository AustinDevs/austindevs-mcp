<?php

use App\Models\McpConnection;
use App\Services\RemoteMcpClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

test('remote tool discovery follows every pagination cursor', function () {
    $connection = McpConnection::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['https://mcp.example.com/mcp' => Http::sequence()
        ->push(['id' => 1, 'result' => ['tools' => [['name' => 'first']], 'nextCursor' => 'page-two']])
        ->push(['id' => 2, 'result' => ['tools' => [['name' => 'second']]]]),
    ]);
    expect((new RemoteMcpClient($connection))->tools())->toBe([['name' => 'first'], ['name' => 'second']]);
    Http::assertSent(fn ($request) => ($request['params']->cursor ?? null) === 'page-two');
});

test('SSE tool results are matched by request ID after notifications', function () {
    $connection = McpConnection::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['https://mcp.example.com/mcp' => Http::response("event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\"}\n\nevent: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"Done\"}]}}\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
    expect((new RemoteMcpClient($connection))->call('search', []))->toBe(['content' => [['type' => 'text', 'text' => 'Done']]]);
    Http::assertSentCount(1);
});

test('failed upstream requests are not retried', function () {
    $connection = McpConnection::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['https://mcp.example.com/mcp' => Http::response('private diagnostics', 500)]);
    expect(fn () => (new RemoteMcpClient($connection))->call('write', []))->toThrow(RuntimeException::class, 'Upstream returned HTTP 500.');
    Http::assertSentCount(1);
});

test('disabling a connection prevents calls through an already constructed client', function () {
    $connection = McpConnection::factory()->create();
    $client = new RemoteMcpClient($connection);
    $connection->update(['enabled' => false]);
    Http::preventStrayRequests();
    expect(fn () => $client->call('write', []))->toThrow(RuntimeException::class, 'This connection is disabled.');
    Http::assertNothingSent();
});

test('connections sharing a Workspace URL send only their own Google token', function () {
    $url = 'http://google-workspace-mcp:8000/mcp';
    $personal = McpConnection::factory()->create(['name' => 'google_personal', 'url' => $url, 'auth_type' => 'oauth', 'credentials' => ['access_token' => 'personal-token']]);
    $work = McpConnection::factory()->create(['name' => 'google_austindevs', 'url' => $url, 'auth_type' => 'oauth', 'credentials' => ['access_token' => 'work-token']]);
    Http::preventStrayRequests();
    Http::fake([$url => Http::response(['id' => 1, 'result' => ['content' => []]])]);

    (new RemoteMcpClient($personal))->call('personal_search', []);
    (new RemoteMcpClient($work))->call('work_search', []);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['params']->name === 'personal_search' && $request->hasHeader('Authorization', 'Bearer personal-token'));
    Http::assertSent(fn ($request) => $request['params']->name === 'work_search' && $request->hasHeader('Authorization', 'Bearer work-token'));
    expect($personal->fresh()->credentials['access_token'])->toBe('personal-token');
    expect($work->fresh()->credentials['access_token'])->toBe('work-token');
});
