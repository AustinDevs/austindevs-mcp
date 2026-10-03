<?php

use App\Models\GatewayLog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

const UPLOAD_TOKEN = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';

test('upload bytes are forwarded to the vault upload link and its answer is returned', function () {
    Http::preventStrayRequests();
    Http::fake(['http://obsidian-mcpvault:3333/upload/*' => Http::response(['success' => true, 'path' => 'Personal/Claim/photo.jpg', 'size' => 5], 200)]);

    $this->call('PUT', '/obsidian-upload/'.UPLOAD_TOKEN, [], [], [], ['CONTENT_TYPE' => 'image/jpeg'], "\xff\xd8\xff\x00\x01")
        ->assertOk()->assertJson(['success' => true, 'path' => 'Personal/Claim/photo.jpg']);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'http://obsidian-mcpvault:3333/upload/'.UPLOAD_TOKEN
        && $request->body() === "\xff\xd8\xff\x00\x01"
        && $request->hasHeader('Content-Type', 'application/octet-stream'));
    expect(GatewayLog::query()->where('category', 'upload')->count())->toBe(1);
    expect(GatewayLog::query()->get()->toJson())->not->toContain(UPLOAD_TOKEN);
});

test('POST is accepted too and only a valid sha256 query is passed along', function () {
    Http::preventStrayRequests();
    Http::fake(['http://obsidian-mcpvault:3333/upload/*' => Http::response(['success' => true], 200)]);
    $sha = str_repeat('a', 64);

    $this->call('POST', '/obsidian-upload/'.UPLOAD_TOKEN.'?sha256='.$sha.'&evil=1', [], [], [], [], 'data')->assertOk();
    $this->call('POST', '/obsidian-upload/'.UPLOAD_TOKEN.'?sha256=nothex', [], [], [], [], 'data')->assertOk();

    Http::assertSent(fn ($request) => $request->url() === 'http://obsidian-mcpvault:3333/upload/'.UPLOAD_TOKEN.'?sha256='.$sha);
    Http::assertSent(fn ($request) => $request->url() === 'http://obsidian-mcpvault:3333/upload/'.UPLOAD_TOKEN);
});

test('vault errors such as an expired link keep their status', function () {
    Http::preventStrayRequests();
    Http::fake(['http://obsidian-mcpvault:3333/upload/*' => Http::response(['error' => 'unknown_or_expired_upload_link'], 404)]);

    $this->call('PUT', '/obsidian-upload/'.UPLOAD_TOKEN, [], [], [], [], 'data')
        ->assertNotFound()->assertJson(['error' => 'unknown_or_expired_upload_link']);
});

test('an unreachable vault is a 502 and a non-JSON answer is not passed through', function () {
    Http::preventStrayRequests();
    Http::fake(['http://obsidian-mcpvault:3333/upload/*' => Http::sequence()
        ->pushFailedConnection('refused')
        ->push('<html>boom</html>', 200),
    ]);

    $this->call('PUT', '/obsidian-upload/'.UPLOAD_TOKEN, [], [], [], [], 'data')->assertStatus(502);
    $this->call('PUT', '/obsidian-upload/'.UPLOAD_TOKEN, [], [], [], [], 'data')->assertStatus(502);
});

test('oversized bodies are refused without calling the vault', function () {
    Http::preventStrayRequests();

    $this->call('PUT', '/obsidian-upload/'.UPLOAD_TOKEN, [], [], [], [], str_repeat('x', 10 * 1024 * 1024 + 1))->assertStatus(413);

    Http::assertNothingSent();
});

test('malformed tokens and other methods never reach the vault', function () {
    Http::preventStrayRequests();

    $this->call('PUT', '/obsidian-upload/short', [], [], [], [], 'data')->assertNotFound();
    $this->call('PUT', '/obsidian-upload/'.str_repeat('a', 20).'.php', [], [], [], [], 'data')->assertNotFound();
    $this->call('GET', '/obsidian-upload/'.UPLOAD_TOKEN)->assertStatus(405);
    $this->call('DELETE', '/obsidian-upload/'.UPLOAD_TOKEN)->assertStatus(405);

    Http::assertNothingSent();
});
