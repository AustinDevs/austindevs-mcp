<?php

use App\Services\FaviconFetcher;
use Illuminate\Support\Facades\Http;

test('favicon discovery resolves an HTML icon and stores raster bytes', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1sAAAAASUVORK5CYII=');
    Http::preventStrayRequests();
    Http::fake([
        'https://example.com/' => Http::response('<html><head><link rel="shortcut icon" href="/assets/icon.png"></head></html>'),
        'https://example.com/assets/icon.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
    ]);
    expect(app(FaviconFetcher::class)->fetch('https://example.com/mcp'))->toBe('data:image/png;base64,'.base64_encode($png));
    Http::assertSentCount(2);
});

test('missing favicon and active content return a harmless fallback', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://example.com/' => Http::response('', 404),
        'https://example.com/favicon.ico' => Http::response('<svg onload="alert(1)"></svg>', 200, ['Content-Type' => 'image/svg+xml']),
    ]);
    expect(app(FaviconFetcher::class)->fetch('https://example.com/mcp'))->toBeNull();
    Http::assertSentCount(2);
});

test('clean SVG favicons are accepted', function () {
    $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#f06a6a"/></svg>';
    Http::preventStrayRequests();
    Http::fake([
        'https://example.com/' => Http::response('<html><head><link rel="icon" type="image/svg+xml" href="/icon.svg"></head></html>'),
        'https://example.com/icon.svg' => Http::response($svg, 200, ['Content-Type' => 'image/svg+xml']),
    ]);
    expect(app(FaviconFetcher::class)->fetch('https://example.com/mcp'))->toBe('data:image/svg+xml;base64,'.base64_encode($svg));
});
