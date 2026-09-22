<?php

use App\Filament\Resources\GatewayLogs\Pages\ListGatewayLogs;
use App\Models\GatewayLog;
use App\Models\McpConnection;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;

uses(LazilyRefreshDatabase::class);

function logPageOwner(): User
{
    $owner = User::factory()->create();
    $owner->forceFill(['gateway_token_hash' => hash('sha256', 'test-token')])->save();
    test()->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    return $owner;
}

test('the activity log page lists filters and shows details', function () {
    logPageOwner();
    $connection = McpConnection::factory()->create(['name' => 'Sentry']);
    $error = GatewayLog::factory()->create(['level' => 'error', 'category' => 'upstream', 'message' => 'Upstream returned HTTP 500', 'mcp_connection_id' => $connection->id, 'context' => ['status' => 500, 'body' => 'upstream exploded']]);
    $info = GatewayLog::factory()->create(['level' => 'info', 'category' => 'oauth', 'message' => 'Discovered OAuth server']);
    $this->get('/gateway-logs')->assertOk()->assertSee('Activity log');
    Livewire::test(ListGatewayLogs::class)
        ->assertCanSeeTableRecords([$error, $info])
        ->filterTable('level', 'error')->assertCanSeeTableRecords([$error])->assertCanNotSeeTableRecords([$info])
        ->resetTableFilters()->filterTable('category', 'oauth')->assertCanSeeTableRecords([$info])->assertCanNotSeeTableRecords([$error])
        ->resetTableFilters()->filterTable('mcp_connection_id', $connection->id)->assertCanSeeTableRecords([$error])->assertCanNotSeeTableRecords([$info])
        ->resetTableFilters()->searchTable('Discovered')->assertCanSeeTableRecords([$info])->assertCanNotSeeTableRecords([$error]);
    Livewire::test(ListGatewayLogs::class)->assertActionExists(TestAction::make('details')->table($error));
    $this->view('filament.gateway-logs.details', ['record' => $error])->assertSee('upstream exploded')->assertSee('Sentry')->assertSee('"status": 500');
});

test('the activity log can be cleared and is read only', function () {
    logPageOwner();
    GatewayLog::factory()->count(3)->create();
    Livewire::test(ListGatewayLogs::class)->assertActionDoesNotExist('create')->callAction('clear')->assertHasNoActionErrors();
    expect(GatewayLog::count())->toBe(0);
});

test('other users cannot open the activity log', function () {
    logPageOwner();
    $this->actingAs(User::factory()->create())->get('/gateway-logs')->assertForbidden();
});
