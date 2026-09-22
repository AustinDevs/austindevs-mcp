<?php

use App\Filament\Pages\ClientAccess;
use App\Models\GatewayToken;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

function clientAccessOwner(): User
{
    $owner = User::factory()->create();
    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    return $owner;
}

test('named tokens coexist and revoking one leaves the other usable', function () {
    $owner = clientAccessOwner();
    $page = Livewire::test(ClientAccess::class)->callAction('token', data: ['name' => 'Codex', 'header' => ''])->assertHasNoActionErrors();
    $first = GatewayToken::sole();
    $secret = substr($page->get('generatedSecret'), strlen('Authorization: Bearer '));
    $page->callAction('token', data: ['name' => 'Claude', 'header' => 'X-MCP-Token'])->assertHasNoActionErrors();
    $secondSecret = substr($page->get('generatedSecret'), strlen('X-MCP-Token: '));
    $second = GatewayToken::where('name', 'Claude')->sole();
    expect($first->token_hash)->toBe(hash('sha256', $secret));
    expect($first->toArray())->not->toHaveKey('token_hash');
    $this->withToken($secret)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();
    expect($first->fresh()->last_used_at)->not->toBeNull();

    $page->callAction('revokeToken', arguments: ['id' => $first->id])->assertHasNoActionErrors();

    expect($first->fresh()->revoked_at)->not->toBeNull();
    expect($second->fresh()->revoked_at)->toBeNull();
    $this->withToken($secret)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    $this->withHeader('X-MCP-Token', $secondSecret)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();
});

test('client access displays token records and never displays their stored hashes', function () {
    $owner = clientAccessOwner();
    $token = GatewayToken::factory()->for($owner)->create(['name' => 'Codex laptop']);

    $this->get('/client-access')->assertOk()->assertSee('Codex laptop')->assertSee('Copy endpoint')->assertDontSee($token->token_hash);
});

test('legacy static token remains available for individual revocation', function () {
    $owner = clientAccessOwner();
    $owner->forceFill(['gateway_token_hash' => hash('sha256', 'legacy')])->save();
    $page = Livewire::test(ClientAccess::class)->assertSee('Legacy access token');

    $page->callAction('revokeLegacy')->assertHasNoActionErrors();

    expect($owner->fresh()->gateway_token_hash)->toBeNull();
});

test('revoking an OAuth session also revokes its refresh tokens only', function () {
    $owner = clientAccessOwner();
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Desktop', ['https://client.example.com/callback'], true, $owner);
    $token = Token::create(['id' => str_repeat('a', 80), 'user_id' => $owner->id, 'client_id' => $client->id, 'scopes' => ['mcp:use'], 'revoked' => false, 'expires_at' => now()->addHour()]);
    $other = Token::create(['id' => str_repeat('b', 80), 'user_id' => $owner->id, 'client_id' => $client->id, 'scopes' => ['mcp:use'], 'revoked' => false, 'expires_at' => now()->addHour()]);
    $refresh = RefreshToken::create(['id' => str_repeat('c', 80), 'access_token_id' => $token->id, 'revoked' => false, 'expires_at' => now()->addDay()]);

    Livewire::test(ClientAccess::class)->callAction('revokeOAuth', arguments: ['id' => $token->id])->assertHasNoActionErrors();

    expect($token->fresh()->revoked)->toBeTrue();
    expect($refresh->fresh()->revoked)->toBeTrue();
    expect($other->fresh()->revoked)->toBeFalse();
});

test('revoking a registered client disables it and its grants', function () {
    $owner = clientAccessOwner();
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Desktop', ['https://client.example.com/callback'], true, $owner);
    $token = Token::create(['id' => str_repeat('d', 80), 'user_id' => $owner->id, 'client_id' => $client->id, 'scopes' => ['mcp:use'], 'revoked' => false, 'expires_at' => now()->addHour()]);

    Livewire::test(ClientAccess::class)->callAction('revokeClient', arguments: ['id' => $client->id])->assertHasNoActionErrors();

    expect($client->fresh()->revoked)->toBeTrue();
    expect($token->fresh()->revoked)->toBeTrue();
});

test('token revoke actions cannot target another user record', function () {
    clientAccessOwner();
    $token = GatewayToken::factory()->create();

    expect(fn () => Livewire::test(ClientAccess::class)->callAction('revokeToken', arguments: ['id' => $token->id]))
        ->toThrow(ModelNotFoundException::class);

    expect($token->fresh()->revoked_at)->toBeNull();
});

test('revoke all disconnects named and legacy tokens', function () {
    $owner = clientAccessOwner();
    $owner->forceFill(['gateway_token_hash' => hash('sha256', 'legacy')])->save();
    $tokens = GatewayToken::factory()->for($owner)->count(2)->create();

    Livewire::test(ClientAccess::class)->callAction('revoke')->assertHasNoActionErrors();

    expect(GatewayToken::whereIn('id', $tokens->modelKeys())->whereNull('revoked_at')->count())->toBe(0);
    expect($owner->fresh()->gateway_token_hash)->toBeNull();
});

test('root panel uses Austin Devs branding and ordered navigation without global search', function () {
    clientAccessOwner();

    $this->get('/')->assertRedirect('/mcp-connections');
    $response = $this->get('/client-access')->assertOk()->assertSee('Austin Devs MCP');
    $response->assertSeeInOrder(['MCP Servers', 'Activity log', 'Client Access']);
    $response->assertDontSee('fi-global-search', false);
});
