<?php

use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['services.google.client_id' => 'google-client', 'services.google.client_secret' => 'google-secret']);
});

function googleLoginClient(array $responses, array &$history): void
{
    $handler = HandlerStack::create(new MockHandler($responses));
    $handler->push(Middleware::history($history));
    Socialite::extend('google', fn () => (new GoogleProvider(request(), 'google-client', 'google-secret', route('google.callback')))
        ->setHttpClient(new Client(['handler' => $handler])));
}

test('admin login redirects to Google with state PKCE and identity scopes instead of a password form', function () {
    $response = $this->get('/login')->assertRedirect();

    expect(parse_url($response->headers->get('Location'), PHP_URL_HOST))->toBe('accounts.google.com');
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray([
        'client_id' => 'google-client', 'redirect_uri' => route('google.callback'),
        'scope' => 'openid profile email', 'response_type' => 'code', 'code_challenge_method' => 'S256',
        'login_hint' => 'kevin@austindevs.com', 'prompt' => 'select_account', 'state' => session('state'),
    ]);
    expect($query['state'])->not->toBeEmpty();
    expect($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', session('code_verifier'), true)), '+/', '-_'), '='));
    $this->post('/login', ['email' => 'kevin@austindevs.com', 'password' => 'password'])->assertStatus(405);
});

test('verified owner Google login preserves the existing owner and intended destination', function () {
    $owner = User::factory()->create(['gateway_token_hash' => 'unchanged']);
    $history = [];
    googleLoginClient([
        new Response(200, [], json_encode(['access_token' => 'google-token', 'expires_in' => 3600])),
        new Response(200, [], json_encode(['sub' => 'google-owner', 'email' => 'kevin@austindevs.com', 'email_verified' => true])),
    ], $history);
    $this->withSession(['state' => 'expected', 'code_verifier' => 'verifier', 'url.intended' => route('filament.app.pages.client-access')]);
    $oldSessionId = session()->getId();

    $this->get(route('google.callback', ['state' => 'expected', 'code' => 'google-code']))
        ->assertRedirect(route('filament.app.pages.client-access'));

    $this->assertAuthenticatedAs($owner, 'web');
    expect(session()->getId())->not->toBe($oldSessionId);
    expect($owner->fresh()->gateway_token_hash)->toBe('unchanged');
    expect(User::count())->toBe(1);
    expect($history)->toHaveCount(2);
    parse_str((string) $history[0]['request']->getBody(), $body);
    expect($body)->toMatchArray(['code' => 'google-code', 'code_verifier' => 'verifier', 'redirect_uri' => route('google.callback')]);
    expect($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer google-token');

    Auth::guard('web')->logout();
    Socialite::forgetDrivers();
    $this->get(route('google.callback', ['state' => 'expected', 'code' => 'google-code']))->assertForbidden();
    $this->assertGuest('web');
    expect($history)->toHaveCount(2);
});

test('Google login rejects every identity except the verified allowed email', function (string $email, mixed $verified) {
    User::factory()->create();
    $history = [];
    googleLoginClient([
        new Response(200, [], json_encode(['access_token' => 'google-token'])),
        new Response(200, [], json_encode(['sub' => 'google-user', 'email' => $email, 'email_verified' => $verified])),
    ], $history);

    $this->withSession(['state' => 'expected', 'code_verifier' => 'verifier'])
        ->get(route('google.callback', ['state' => 'expected', 'code' => 'code']))->assertForbidden();

    $this->assertGuest('web');
    expect(User::count())->toBe(1);
})->with([
    'different account' => ['other@austindevs.com', true],
    'unverified owner' => ['kevin@austindevs.com', false],
    'missing verification' => ['kevin@austindevs.com', null],
    'string verification' => ['kevin@austindevs.com', 'false'],
]);

test('Google callback rejects missing and mismatched state without contacting Google', function (?string $storedState, string $receivedState) {
    $history = [];
    googleLoginClient([], $history);

    $this->withSession(['state' => $storedState])->get(route('google.callback', ['state' => $receivedState, 'code' => 'code']))->assertForbidden();

    $this->assertGuest('web');
    expect($history)->toBeEmpty();
})->with([
    'unsolicited callback' => [null, 'unknown'],
    'mismatched state' => ['expected', 'wrong'],
]);

test('Google denial clears the pending login and does not authenticate', function () {
    $this->withSession(['state' => 'expected', 'code_verifier' => 'verifier'])
        ->get(route('google.callback', ['state' => 'expected', 'error' => 'access_denied']))
        ->assertForbidden()->assertSessionMissing('state')->assertSessionMissing('code_verifier');

    $this->assertGuest('web');
});

test('Google token failures leave the visitor unauthenticated', function () {
    $history = [];
    googleLoginClient([new Response(400, [], json_encode(['error' => 'invalid_grant']))], $history);

    $this->withSession(['state' => 'expected', 'code_verifier' => 'verifier'])
        ->get(route('google.callback', ['state' => 'expected', 'code' => 'expired']))->assertServiceUnavailable();

    $this->assertGuest('web');
});

test('Google login fails closed when credentials are not configured', function () {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

    $this->get('/login')->assertServiceUnavailable();

    $this->assertGuest('web');
});

test('Google login does not create an owner when none is configured', function () {
    $history = [];
    googleLoginClient([
        new Response(200, [], json_encode(['access_token' => 'google-token'])),
        new Response(200, [], json_encode(['sub' => 'google-owner', 'email' => 'kevin@austindevs.com', 'email_verified' => true])),
    ], $history);

    $this->withSession(['state' => 'expected', 'code_verifier' => 'verifier'])
        ->get(route('google.callback', ['state' => 'expected', 'code' => 'code']))->assertForbidden();

    $this->assertGuest('web');
    expect(User::count())->toBe(0);
});
