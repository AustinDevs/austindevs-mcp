<?php

use App\Filament\Pages\ClientAccess;
use App\Filament\Resources\McpConnections\Pages\ManageMcpConnections;
use App\Models\McpConnection;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

function gatewayOwner(): User
{
    $owner = User::factory()->create();
    $owner->forceFill(['gateway_token_hash' => hash('sha256', 'test-token')])->save();

    return $owner;
}

function gatewayRequest(string $method, array $params = []): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params];
}

function fakeRemoteMcp(array $result = []): void
{
    Http::preventStrayRequests();
    Http::fake(['https://mcp.example.com/mcp' => function ($request) use ($result) {
        if ($request['method'] === 'notifications/initialized') {
            return Http::response('', 202);
        }
        $body = match ($request['method']) {
            'initialize' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'serverInfo' => ['name' => 'Test', 'version' => '1']],
            'tools/list' => ['tools' => [['name' => 'search', 'description' => 'Search', 'inputSchema' => ['type' => 'object']]]],
            default => $result,
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $body], 200, ['Mcp-Session-Id' => 'upstream-session']);
    }]);
}

test('unauthenticated gateway requests advertise OAuth and return 401', function () {
    $this->postJson('/mcp', gatewayRequest('tools/list'))->assertUnauthorized()->assertHeader('WWW-Authenticate');
    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertJsonPath('resource', url('/mcp'));
});

test('enabled connections appear as wrappers and disabling or deleting removes them', function () {
    gatewayOwner();
    $connection = McpConnection::factory()->create(['name' => 'My server']);
    McpConnection::factory()->create(['enabled' => false]);
    $this->withToken('test-token')->postJson('/mcp', gatewayRequest('tools/list'))->assertOk()
        ->assertJsonCount(2, 'result.tools')->assertJsonPath('result.tools.0.name', $connection->toolName());
    $connection->update(['enabled' => false]);
    $this->withToken('test-token')->postJson('/mcp', gatewayRequest('tools/call', ['name' => $connection->toolName(), 'arguments' => ['tool_name' => 'search']]))->assertJsonPath('error.code', -32602);
    $connection->delete();
    $this->withToken('test-token')->postJson('/mcp', gatewayRequest('tools/list'))->assertJsonCount(1, 'result.tools');
});

test('wrapper discovers tools and forwards credentials and arguments without gateway token passthrough', function () {
    gatewayOwner();
    $connection = McpConnection::factory()->create(['auth_type' => 'bearer', 'credentials' => ['bearer_token' => 'upstream-secret', 'headers' => ['X-Account' => 'personal']]]);
    fakeRemoteMcp(['content' => [['type' => 'text', 'text' => 'Found it']]]);
    $this->withToken('test-token')->postJson('/mcp', gatewayRequest('tools/call', ['name' => $connection->toolName(), 'arguments' => ['tool_name' => 'search', 'arguments' => '{"query":"hello"}']]))
        ->assertOk()->assertJsonPath('result.isError', false);
    Http::assertSent(fn ($request) => $request['method'] === 'tools/call' && $request['params']->arguments->query === 'hello' && $request->hasHeader('Authorization', 'Bearer upstream-secret') && $request->hasHeader('X-Account', 'personal') && $request->hasHeader('Mcp-Session-Id', 'upstream-session'));
    expect($connection->getRawOriginal('credentials'))->not->toContain('upstream-secret');
    expect($connection->toArray())->not->toHaveKey('credentials');
});

test('wrapper rejects non-object arguments and reports upstream errors without retrying', function () {
    gatewayOwner();
    $connection = McpConnection::factory()->create();
    fakeRemoteMcp(['isError' => true, 'content' => [['type' => 'text', 'text' => 'Access denied']]]);
    $this->withToken('test-token')->postJson('/mcp', gatewayRequest('tools/call', ['name' => $connection->toolName(), 'arguments' => ['tool_name' => 'search', 'arguments' => '[]']]))->assertJsonPath('result.isError', true);
    Http::assertNothingSent();
    $this->withToken('test-token')->postJson('/mcp', gatewayRequest('tools/call', ['name' => $connection->toolName(), 'arguments' => ['tool_name' => 'search']]))->assertJsonPath('result.isError', true);
    Http::assertSentCount(3);
});

test('static tokens support custom headers and reject incorrect credentials', function () {
    $owner = gatewayOwner();
    $owner->forceFill(['gateway_header' => 'X-MCP-Token'])->save();
    $this->withHeader('X-MCP-Token', 'wrong')->postJson('/mcp', gatewayRequest('tools/list'))->assertUnauthorized();
    $this->withHeader('X-MCP-Token', 'test-token')->postJson('/mcp', gatewayRequest('tools/list'))->assertOk();
});

test('OAuth access is restricted to the owner and MCP scope', function () {
    $owner = gatewayOwner();
    Passport::actingAs($owner, ['mcp:use']);
    $this->postJson('/mcp', gatewayRequest('tools/list'))->assertOk();
    Passport::actingAs($owner, []);
    $this->postJson('/mcp', gatewayRequest('tools/list'))->assertForbidden();
    Passport::actingAs(User::factory()->create(), ['mcp:use']);
    $this->postJson('/mcp', gatewayRequest('tools/list'))->assertUnauthorized();
});

test('owner can create toggle and delete a connection in the dashboard', function () {
    $this->actingAs(gatewayOwner());
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Http::preventStrayRequests();
    Http::fake(['https://mcp.example.com/' => Http::response('', 404), 'https://mcp.example.com/favicon.ico' => Http::response('', 404)]);
    Livewire::test(ManageMcpConnections::class)->callAction('create', data: ['name' => 'Example', 'url' => 'https://mcp.example.com/mcp', 'auth_type' => 'bearer', 'credentials' => ['bearer_token' => 'private-token', 'headers' => ['X-Test' => 'yes']]])->assertHasNoActionErrors();
    $connection = McpConnection::sole();
    expect($connection->credentials['bearer_token'])->toBe('private-token');
    Livewire::test(ManageMcpConnections::class)->call('updateTableColumnState', 'enabled', $connection->id, false);
    expect($connection->fresh()->enabled)->toBeFalse();
    Livewire::test(ManageMcpConnections::class)->callAction(TestAction::make('delete')->table($connection));
    $this->assertDatabaseMissing('mcp_connections', ['id' => $connection->id]);
    Http::assertSentCount(2);
});

test('dashboard and upstream OAuth routes reject other users', function () {
    gatewayOwner();
    $other = User::factory()->create();
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth']);
    $this->actingAs($other)->get('/app/mcp-connections')->assertForbidden();
    $this->post(route('upstream.connect', $connection))->assertForbidden();
});

test('OAuth callback rejects missing expired mismatched and replayed state', function () {
    $this->actingAs(gatewayOwner());
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth']);
    $this->get(route('upstream.callback').'?state=bad&code=code')->assertForbidden();
    $state = str_repeat('a', 64);
    $this->withSession(['upstream-states' => [$state => $connection->id], 'upstream' => [$connection->id => ['state' => $state, 'verifier' => 'verifier', 'expires' => now()->subMinute()->timestamp]]])
        ->get(route('upstream.callback').'?state='.$state.'&code=code')->assertForbidden();
});

test('clients can dynamically register for OAuth', function () {
    $this->postJson('/oauth/register', ['client_name' => 'Desktop', 'redirect_uris' => ['http://localhost:4567/callback'], 'token_endpoint_auth_method' => 'none'])
        ->assertSuccessful()->assertJsonStructure(['client_id']);
});

test('client access page generates and revokes a static token', function () {
    $owner = gatewayOwner();
    $this->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Livewire::test(ClientAccess::class)->callAction('token', data: ['header' => 'X-MCP-Token'])->assertHasNoActionErrors();
    expect($owner->fresh()->gateway_header)->toBe('X-MCP-Token');
    Livewire::test(ClientAccess::class)->callAction('revoke')->assertHasNoActionErrors();
    expect($owner->fresh()->gateway_token_hash)->toBeNull();
});

test('owner can issue a confidential OAuth client for manual configuration', function () {
    $this->actingAs(gatewayOwner());
    Filament::setCurrentPanel(Filament::getPanel('app'));
    $page = Livewire::test(ClientAccess::class)->callAction('oauthClient', data: ['name' => 'My client', 'redirect' => 'http://localhost:5678/callback'])->assertHasNoActionErrors();
    expect($page->get('generatedSecret'))->toContain('Client ID:', 'Client secret:');
    $client = Client::where('name', 'My client')->sole();
    expect($client->redirect_uris)->toBe(['http://localhost:5678/callback']);
    expect($client->secret)->not->toBeNull();
});

test('dashboard validates remote URLs before fetching icons', function () {
    $this->actingAs(gatewayOwner());
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Http::preventStrayRequests();
    Livewire::test(ManageMcpConnections::class)->callAction('create', data: ['name' => 'Invalid', 'url' => 'file:///etc/passwd', 'auth_type' => 'none'])->assertHasActionErrors(['url']);
    expect(McpConnection::count())->toBe(0);
    Http::assertNothingSent();
});

test('owner creation refuses a second account', function () {
    gatewayOwner();
    $this->artisan('gateway:owner', ['email' => 'second@example.com'])->assertFailed();
    expect(User::count())->toBe(1);
});

test('desktop clients can register a Claude callback scheme', function () {
    $this->postJson('/oauth/register', ['client_name' => 'Claude Desktop', 'redirect_uris' => ['claude://oauth/callback']])->assertCreated();
});
