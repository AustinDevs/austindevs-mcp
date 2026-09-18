<?php

use App\Models\McpConnection;
use App\Services\ServiceIcon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('brand icons resolve from the connection name', function (string $name, string $expected) {
    expect(app(ServiceIcon::class)->resolve($name, 'https://example.com/mcp'))->toBe($expected);
})->with([
    ['Asana (DineUp)', '/img/services/asana.svg'],
    ['Actual Budget MCP', '/img/services/actual-budget.svg'],
    ['Spark Email MCP', '/img/services/spark.png'],
    ['Browser MCP', '/img/services/chrome.svg'],
    ['Hermes MCP', '/img/services/hermes.png'],
    ['Trello (Zollege)', '/img/services/trello.png'],
    ['Google Calendar', '/img/services/google-calendar.svg'],
    ['Google Workspace', '/img/services/google-workspace.png'],
    ['My Google thing', '/img/services/google.svg'],
    ['Dropbox files', '/img/services/dropbox.svg'],
]);

test('brand icons resolve from the URL host when the name does not match', function () {
    $icons = app(ServiceIcon::class);
    expect($icons->resolve('Errors', 'https://mcp.sentry.dev/mcp'))->toBe('/img/services/sentry.png');
    expect($icons->resolve('Deploys', 'https://api.fly.io/mcp'))->toBe('/img/services/fly.svg');
    expect($icons->resolve('Tweets', 'https://mcp.x.com/mcp'))->toBe('/img/services/x.svg');
    expect($icons->resolve('Custom', 'https://mcp.internal.example/mcp'))->toBeNull();
    expect($icons->resolve('Custom', null))->toBeNull();
});

test('generic words and partial matches do not pick an icon', function () {
    $icons = app(ServiceIcon::class);
    expect($icons->resolve('Inbox', 'https://example.com'))->toBeNull();
    expect($icons->resolve('Slackers united', 'https://example.com'))->toBeNull();
    expect($icons->resolve('Notebooker', 'https://notebooker.ai/mcp'))->toBeNull();
});

test('connections prefer the fetched favicon then the brand icon then the placeholder', function () {
    $fetched = McpConnection::factory()->create(['name' => 'Asana', 'favicon' => 'data:image/png;base64,AAAA']);
    $brand = McpConnection::factory()->create(['name' => 'Asana', 'favicon' => null]);
    $plain = McpConnection::factory()->create(['name' => 'Internal tools', 'favicon' => null]);
    expect($fetched->iconUrl())->toBe('data:image/png;base64,AAAA');
    expect($brand->iconUrl())->toBe('/img/services/asana.svg');
    expect($plain->iconUrl())->toBe('/img/services/default.svg');
});
