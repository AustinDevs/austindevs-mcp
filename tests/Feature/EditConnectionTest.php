<?php

use App\Filament\Resources\McpConnections\Pages\ManageMcpConnections;
use App\Models\McpConnection;
use App\Models\User;
use App\Services\ConnectionEditor;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

function editOwner(): void
{
    $owner = User::factory()->create();
    $owner->forceFill(['gateway_token_hash' => hash('sha256', 'test-token')])->save();
    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
}

function oauthConnection(): McpConnection
{
    return McpConnection::factory()->create(['name' => 'Old', 'auth_type' => 'oauth', 'status' => 'Connected', 'favicon' => 'data:old', 'credentials' => [
        'client_id' => 'cid', 'client_secret' => 'csecret', 'scope' => 'read', 'headers' => ['X-A' => '1'],
        'access_token' => 'at', 'refresh_token' => 'rt', 'expires_at' => 123, 'metadata' => ['issuer' => 'https://auth.example.com'],
    ]]);
}

test('editing keeps OAuth tokens and merges form credentials', function () {
    editOwner();
    $connection = oauthConnection();
    Http::preventStrayRequests();
    Livewire::test(ManageMcpConnections::class)->mountAction(TestAction::make('edit')->table($connection))
        ->assertSchemaStateSet(['name' => 'Old', 'credentials.client_id' => 'cid', 'credentials.headers' => ['X-A' => '1']]);
    Livewire::test(ManageMcpConnections::class)->callAction(TestAction::make('edit')->table($connection), data: [
        'name' => 'Renamed', 'url' => $connection->url, 'auth_type' => 'oauth',
        'credentials' => ['client_id' => 'cid', 'client_secret' => '', 'scope' => 'read write', 'headers' => ['X-B' => '2']],
    ])->assertHasNoActionErrors();
    $fresh = $connection->fresh();
    expect($fresh->name)->toBe('Renamed')->and($fresh->status)->toBe('Connected')->and($fresh->favicon)->toBe('data:old');
    expect($fresh->credentials)->toMatchArray(['client_id' => 'cid', 'scope' => 'read write', 'headers' => ['X-B' => '2'], 'access_token' => 'at', 'refresh_token' => 'rt', 'expires_at' => 123, 'metadata' => ['issuer' => 'https://auth.example.com']]);
    expect($fresh->credentials)->not->toHaveKey('client_secret');
    Http::assertNothingSent();
});

test('changing the URL resets OAuth session data and refetches the favicon', function () {
    editOwner();
    $connection = oauthConnection();
    Http::preventStrayRequests();
    Http::fake(['https://other.example.com/' => Http::response('', 404), 'https://other.example.com/favicon.ico' => Http::response('', 404)]);
    Livewire::test(ManageMcpConnections::class)->callAction(TestAction::make('edit')->table($connection), data: [
        'name' => 'Old', 'url' => 'https://other.example.com/mcp', 'auth_type' => 'oauth',
        'credentials' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'scope' => 'read', 'headers' => ['X-A' => '1']],
    ])->assertHasNoActionErrors();
    $fresh = $connection->fresh();
    expect($fresh->url)->toBe('https://other.example.com/mcp')->and($fresh->status)->toBe('Authorization required')->and($fresh->favicon)->toBeNull();
    expect($fresh->credentials)->toBe(['client_id' => 'cid', 'client_secret' => 'csecret', 'scope' => 'read', 'headers' => ['X-A' => '1']]);
    Http::assertSentCount(2);
});

test('changing auth type to bearer drops OAuth data and stores the bearer token', function () {
    editOwner();
    $connection = oauthConnection();
    Http::preventStrayRequests();
    app(ConnectionEditor::class)->update($connection, ['name' => 'Old', 'url' => $connection->url, 'auth_type' => 'bearer', 'credentials' => ['bearer_token' => 'bt', 'headers' => []]]);
    $fresh = $connection->fresh();
    expect($fresh->auth_type)->toBe('bearer')->and($fresh->status)->toBe('Not checked');
    expect($fresh->credentials)->toBe(['bearer_token' => 'bt']);
    Http::assertNothingSent();
});

test('editing validates the URL', function () {
    editOwner();
    $connection = McpConnection::factory()->create();
    Http::preventStrayRequests();
    Livewire::test(ManageMcpConnections::class)->callAction(TestAction::make('edit')->table($connection), data: ['name' => 'X', 'url' => 'file:///etc/passwd', 'auth_type' => 'none'])->assertHasActionErrors(['url']);
    Http::assertNothingSent();
});
