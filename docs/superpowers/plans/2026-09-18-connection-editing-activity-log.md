# Connection Editing, Activity Log, and Upstream OAuth Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the owner edit MCP connections, record gateway activity (tool calls, upstream failures, OAuth steps, rejected requests) in SQLite, show it on a dashboard page and through a `gateway_logs` MCP tool, and make upstream OAuth failures report their real cause.

**Architecture:** A `gateway_logs` table written only by `App\Services\ActivityLogger` (which redacts secrets and truncates long strings, and never throws). Existing services (`ConnectionTool`, `RemoteMcpClient`, `UpstreamOAuth`, `UpstreamOAuthController`, `AuthenticateGateway`) call the logger at their failure and milestone points. A read-only Filament resource and an MCP tool read the table. A `ConnectionEditor` service applies edits so form credentials merge into stored credentials without wiping OAuth tokens.

**Tech Stack:** Laravel 13.31, Filament 5.8, Laravel MCP 0.9.4, Pest 5, SQLite.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-09-18-connection-editing-activity-log-design.md`.
- Read `.ai/rules/app.md` before editing anything under `app/`.
- PHP: curly braces always, constructor promotion, explicit return types, PHPDoc over inline comments.
- After modifying PHP files run `vendor/bin/pint --dirty --format agent`.
- Tests are Pest feature tests using `LazilyRefreshDatabase` and model factories. Run the narrowest file: `vendor/bin/pest tests/Feature/<File>.php`.
- Use `php artisan make:...` generators with `--no-interaction` to create files, then replace their contents with the code shown.
- Log levels: `info`, `warning`, `error`. Categories: `tool_call`, `upstream`, `oauth`, `auth`.
- Redacted keys (case-insensitive exact match): `authorization`, `proxy-authorization`, `access_token`, `refresh_token`, `bearer_token`, `client_secret`, `code`, `code_verifier`, `token`, `secret`, `password`, `cookie`, `set-cookie`. Replacement string: `[redacted]`. Strings longer than 2,000 characters are truncated with suffix `… [truncated]`.
- Prune rows older than 30 days.
- Error text returned to MCP clients from `ConnectionTool` stays exactly `Upstream connection failed. Check this server in the dashboard; reconnect if authentication expired.`
- Token failure exception message stays exactly `OAuth token exchange failed. Reconnect this server.`
- Commit after each task with a message body ending in `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

---

### Task 1: `GatewayLog` model, migration, factory, and `ActivityLogger`

**Files:**
- Create: `database/migrations/2026_09_18_000001_create_gateway_logs_table.php`
- Create: `app/Models/GatewayLog.php`
- Create: `database/factories/GatewayLogFactory.php`
- Create: `app/Services/ActivityLogger.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/ActivityLoggerTest.php`

**Interfaces:**
- Produces: `App\Models\GatewayLog` with columns `level`, `category`, `mcp_connection_id`, `message`, `context` (array), `duration_ms`, `created_at`; relation `connection()`.
- Produces: `App\Services\ActivityLogger` with `info|warning|error(string $category, string $message, array $context = [], ?McpConnection $connection = null, ?int $durationMs = null): ?GatewayLog` and `redact(array $context): array`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\GatewayLog;
use App\Models\McpConnection;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(LazilyRefreshDatabase::class);

test('logger records rows with level category connection and duration', function () {
    $connection = McpConnection::factory()->create();
    $log = app(ActivityLogger::class)->info('tool_call', 'Called search', ['tool_name' => 'search'], $connection, 120);
    expect($log)->toBeInstanceOf(GatewayLog::class);
    expect($log->fresh())->toMatchArray(['level' => 'info', 'category' => 'tool_call', 'message' => 'Called search', 'mcp_connection_id' => $connection->id, 'duration_ms' => 120, 'context' => ['tool_name' => 'search']]);
    expect($log->connection->is($connection))->toBeTrue();
    app(ActivityLogger::class)->warning('auth', 'Rejected');
    app(ActivityLogger::class)->error('upstream', 'Failed');
    expect(GatewayLog::pluck('level')->all())->toBe(['info', 'warning', 'error']);
    expect(GatewayLog::latest('id')->first()->context)->toBeNull();
});

test('logger redacts secret keys recursively and truncates long strings', function () {
    $context = [
        'headers' => ['Authorization' => 'Bearer abc', 'X-Account' => 'me', 'Set-Cookie' => 'x'],
        'access_token' => 'tok', 'nested' => ['client_secret' => 's', 'code' => 'c', 'ok' => 'fine'],
        'body' => str_repeat('a', 2500), 'has_code' => true, 'error_code' => 'invalid_grant',
    ];
    $redacted = app(ActivityLogger::class)->redact($context);
    expect($redacted['headers'])->toBe(['Authorization' => '[redacted]', 'X-Account' => 'me', 'Set-Cookie' => '[redacted]']);
    expect($redacted['access_token'])->toBe('[redacted]');
    expect($redacted['nested'])->toBe(['client_secret' => '[redacted]', 'code' => '[redacted]', 'ok' => 'fine']);
    expect($redacted['body'])->toEndWith('… [truncated]')->and(strlen($redacted['body']))->toBeLessThan(2100);
    expect($redacted['has_code'])->toBeTrue()->and($redacted['error_code'])->toBe('invalid_grant');
    $log = app(ActivityLogger::class)->error('oauth', str_repeat('m', 300), $context);
    expect(strlen($log->fresh()->message))->toBe(255);
    expect($log->fresh()->context['access_token'])->toBe('[redacted]');
});

test('logger never throws when the table is unavailable', function () {
    Log::spy();
    DB::statement('DROP TABLE gateway_logs');
    expect(app(ActivityLogger::class)->info('auth', 'still fine'))->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});

test('logs older than thirty days are prunable', function () {
    GatewayLog::factory()->create(['created_at' => now()->subDays(31)]);
    $recent = GatewayLog::factory()->create(['created_at' => now()->subDays(29)]);
    $this->artisan('model:prune', ['--model' => [GatewayLog::class]])->assertSuccessful();
    expect(GatewayLog::pluck('id')->all())->toBe([$recent->id]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/ActivityLoggerTest.php`
Expected: FAIL with `Class "App\Services\ActivityLogger" not found` or similar.

- [ ] **Step 3: Generate files**

```bash
php artisan make:model GatewayLog --migration --factory --no-interaction
php artisan make:class Services/ActivityLogger --no-interaction
```

Rename the generated migration to `database/migrations/2026_09_18_000001_create_gateway_logs_table.php`.

- [ ] **Step 4: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('level', 16);
            $table->string('category', 32);
            $table->foreignId('mcp_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message');
            $table->json('context')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['category', 'created_at']);
            $table->index(['level', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_logs');
    }
};
```

- [ ] **Step 5: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayLog extends Model
{
    use HasFactory;
    use MassPrunable;

    public const LEVELS = ['info', 'warning', 'error'];

    public const CATEGORIES = ['tool_call', 'upstream', 'oauth', 'auth'];

    public $timestamps = false;

    protected $fillable = ['level', 'category', 'mcp_connection_id', 'message', 'context', 'duration_ms', 'created_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'duration_ms' => 'integer', 'created_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(McpConnection::class, 'mcp_connection_id');
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<=', now()->subDays(30));
    }
}
```

- [ ] **Step 6: Write the factory**

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class GatewayLogFactory extends Factory
{
    public function definition(): array
    {
        return ['level' => 'info', 'category' => 'tool_call', 'message' => fake()->sentence(), 'context' => null, 'created_at' => now()];
    }
}
```

- [ ] **Step 7: Write the logger**

```php
<?php

namespace App\Services;

use App\Models\GatewayLog;
use App\Models\McpConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ActivityLogger
{
    private const REDACTED_KEYS = ['authorization', 'proxy-authorization', 'access_token', 'refresh_token', 'bearer_token', 'client_secret', 'code', 'code_verifier', 'token', 'secret', 'password', 'cookie', 'set-cookie'];

    private const MAX_STRING_LENGTH = 2000;

    /** @param array<string, mixed> $context */
    public function info(string $category, string $message, array $context = [], ?McpConnection $connection = null, ?int $durationMs = null): ?GatewayLog
    {
        return $this->record('info', $category, $message, $context, $connection, $durationMs);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $category, string $message, array $context = [], ?McpConnection $connection = null, ?int $durationMs = null): ?GatewayLog
    {
        return $this->record('warning', $category, $message, $context, $connection, $durationMs);
    }

    /** @param array<string, mixed> $context */
    public function error(string $category, string $message, array $context = [], ?McpConnection $connection = null, ?int $durationMs = null): ?GatewayLog
    {
        return $this->record('error', $category, $message, $context, $connection, $durationMs);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = $this->redact($value);
            } elseif (is_string($value)) {
                $context[$key] = Str::limit($value, self::MAX_STRING_LENGTH, '… [truncated]');
            }
        }

        return $context;
    }

    /** @param array<string, mixed> $context */
    private function record(string $level, string $category, string $message, array $context, ?McpConnection $connection, ?int $durationMs): ?GatewayLog
    {
        try {
            return GatewayLog::create([
                'level' => $level, 'category' => $category, 'mcp_connection_id' => $connection?->id,
                'message' => Str::limit($message, 255, ''), 'context' => $context === [] ? null : $this->redact($context),
                'duration_ms' => $durationMs, 'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Activity log write failed: '.$exception->getMessage());

            return null;
        }
    }
}
```

- [ ] **Step 8: Schedule pruning**

Replace `routes/console.php` with:

```php
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('model:prune')->daily();
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/ActivityLoggerTest.php`
Expected: 4 passed.

- [ ] **Step 10: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "Add gateway activity log model and redacting logger" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 2: Record tool calls and upstream failures

**Files:**
- Modify: `app/Mcp/Tools/ConnectionTool.php`
- Modify: `app/Services/RemoteMcpClient.php`
- Test: `tests/Feature/GatewayLoggingTest.php`

**Interfaces:**
- Consumes: `ActivityLogger` from Task 1.

- [ ] **Step 1: Write the failing tests**

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/GatewayLoggingTest.php`
Expected: FAIL (no rows in `gateway_logs`).

- [ ] **Step 3: Update `RemoteMcpClient::rpc`**

Replace the block from `if (! $response->successful())` to the end of the method with:

```php
        if (! $response->successful()) {
            app(ActivityLogger::class)->error('upstream', 'Upstream returned HTTP '.$response->status(), ['method' => $method, 'status' => $response->status(), 'body' => $response->body()], $this->connection);
            throw new RuntimeException($response->status() === 401 ? 'Authentication expired. Reconnect this server.' : 'Upstream returned HTTP '.$response->status().'.');
        }
        $this->sessionId = $response->header('Mcp-Session-Id') ?: $this->sessionId;
        if ($notification) {
            return [];
        }
        $body = $response->json();
        if (str_contains($response->header('Content-Type'), 'text/event-stream')) {
            $body = null;
            foreach (preg_split('/\r?\n\r?\n/', $response->body()) as $event) {
                $data = [];
                foreach (preg_split('/\r?\n/', $event) as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $data[] = ltrim(substr($line, 5));
                    }
                }
                $message = json_decode(implode("\n", $data), true);
                if (is_array($message) && ($message['id'] ?? null) === $id) {
                    $body = $message;
                    break;
                }
            }
        }
        if (! is_array($body) || ($body['id'] ?? null) !== $id || isset($body['error']) || ! is_array($body['result'] ?? null)) {
            app(ActivityLogger::class)->error('upstream', 'Upstream returned an invalid response', ['method' => $method, 'status' => $response->status(), 'body' => $response->body(), 'rpc_error' => is_array($body) ? ($body['error'] ?? null) : null], $this->connection);
            throw new RuntimeException('Upstream returned an invalid response or a protocol error.');
        }

        return $body['result'];
```

- [ ] **Step 4: Update `ConnectionTool::handle`**

Replace the `handle` method with:

```php
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['tool_name' => ['required', 'string'], 'arguments' => ['sometimes', 'string', 'json']]);
        $arguments = json_decode($validated['arguments'] ?? '{}');
        if (! $arguments instanceof \stdClass) {
            return Response::error('Arguments must be a JSON object.');
        }
        $logger = app(ActivityLogger::class);
        $started = hrtime(true);
        try {
            $client = new RemoteMcpClient($this->connection);
            $client->initialize();
            $result = $validated['tool_name'] === 'list_available_tools' ? ['tools' => $client->tools()] : $client->call($validated['tool_name'], (array) $arguments);
            $this->connection->update(['status' => 'Connected']);
            $encoded = json_encode($result, JSON_THROW_ON_ERROR);
            $logger->info('tool_call', 'Called '.$validated['tool_name'].' on '.$this->connection->name, [
                'tool_name' => $validated['tool_name'], 'argument_bytes' => strlen($validated['arguments'] ?? '{}'), 'result_bytes' => strlen($encoded), 'is_error' => ! empty($result['isError']),
            ], $this->connection, (int) ((hrtime(true) - $started) / 1_000_000));

            return ! empty($result['isError']) ? Response::error($encoded) : Response::json($result);
        } catch (Throwable $exception) {
            $logger->error('tool_call', 'Tool call failed on '.$this->connection->name, [
                'tool_name' => $validated['tool_name'], 'exception' => $exception::class, 'error' => $exception->getMessage(),
            ], $this->connection, (int) ((hrtime(true) - $started) / 1_000_000));
            if ($this->connection->exists && McpConnection::whereKey($this->connection->id)->exists()) {
                $this->connection->update(['status' => 'Connection needs attention']);
            }

            return Response::error('Upstream connection failed. Check this server in the dashboard; reconnect if authentication expired.');
        }
    }
```

Add `use App\Services\ActivityLogger;` to both files.

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/GatewayLoggingTest.php tests/Feature/McpGatewayTest.php tests/Feature/RemoteMcpClientTest.php`
Expected: all pass.

- [ ] **Step 6: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "Log gateway tool calls and upstream failures" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 3: `gateway_logs` MCP tool

**Files:**
- Create: `app/Mcp/Tools/GatewayLogsTool.php`
- Modify: `app/Mcp/Servers/UnifiedServer.php`
- Modify: `tests/Feature/McpGatewayTest.php` (tool count assertions)
- Test: `tests/Feature/GatewayLogsToolTest.php`

**Interfaces:**
- Consumes: `GatewayLog` (Task 1).
- Produces: MCP tool named `gateway_logs`, registered last in `UnifiedServer`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\GatewayLog;
use App\Models\McpConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function logsOwner(): void
{
    $owner = User::factory()->create();
    $owner->forceFill(['gateway_token_hash' => hash('sha256', 'test-token')])->save();
}

function fetchLogs(array $arguments = []): array
{
    $response = test()->withToken('test-token')->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'gateway_logs', 'arguments' => (object) $arguments]]);
    $response->assertOk();

    return ['isError' => $response->json('result.isError'), 'body' => json_decode($response->json('result.content.0.text'), true), 'text' => $response->json('result.content.0.text')];
}

test('the log tool is always listed and returns recent rows newest first', function () {
    logsOwner();
    $this->withToken('test-token')->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])
        ->assertOk()->assertJsonPath('result.tools.0.name', 'gateway_logs');
    $connection = McpConnection::factory()->create(['name' => 'Sentry']);
    $older = GatewayLog::factory()->create(['message' => 'older', 'created_at' => now()->subMinute(), 'mcp_connection_id' => $connection->id, 'context' => ['status' => 500], 'duration_ms' => 12]);
    $newer = GatewayLog::factory()->create(['message' => 'newer', 'created_at' => now()]);
    $result = fetchLogs();
    expect($result['isError'])->toBeFalse();
    expect(array_column($result['body']['logs'], 'message'))->toBe(['newer', 'older']);
    expect($result['body']['logs'][1])->toMatchArray(['id' => $older->id, 'level' => 'info', 'category' => 'tool_call', 'connection' => 'Sentry', 'duration_ms' => 12, 'context' => ['status' => 500]]);
    expect($result['body']['logs'][0]['connection'])->toBeNull();
    expect($result['body']['logs'][0]['created_at'])->toBe($newer->created_at->toIso8601String());
});

test('the log tool filters by level category connection since and search and caps the limit', function () {
    logsOwner();
    $sentry = McpConnection::factory()->create(['name' => 'Sentry']);
    GatewayLog::factory()->create(['level' => 'error', 'category' => 'upstream', 'message' => 'Upstream returned HTTP 500', 'mcp_connection_id' => $sentry->id]);
    GatewayLog::factory()->create(['level' => 'info', 'category' => 'oauth', 'message' => 'Discovered OAuth server', 'created_at' => now()->subHours(3)]);
    GatewayLog::factory()->create(['level' => 'warning', 'category' => 'auth', 'message' => 'Gateway request rejected']);
    expect(array_column(fetchLogs(['level' => 'error'])['body']['logs'], 'message'))->toBe(['Upstream returned HTTP 500']);
    expect(array_column(fetchLogs(['category' => 'oauth'])['body']['logs'], 'message'))->toBe(['Discovered OAuth server']);
    expect(array_column(fetchLogs(['connection' => 'sent'])['body']['logs'], 'message'))->toBe(['Upstream returned HTTP 500']);
    expect(fetchLogs(['since' => '1 hour ago'])['body']['logs'])->toHaveCount(2);
    expect(fetchLogs(['since' => now()->subDay()->toIso8601String()])['body']['logs'])->toHaveCount(3);
    expect(array_column(fetchLogs(['search' => 'rejected'])['body']['logs'], 'message'))->toBe(['Gateway request rejected']);
    expect(fetchLogs(['limit' => 1])['body']['logs'])->toHaveCount(1);
    expect(fetchLogs(['limit' => 500])['isError'])->toBeTrue();
    $invalid = fetchLogs(['since' => 'not a date']);
    expect($invalid['isError'])->toBeTrue()->and($invalid['text'])->toContain('since');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/GatewayLogsToolTest.php`
Expected: FAIL (`tools.0.name` is not `gateway_logs`).

- [ ] **Step 3: Create the tool**

```bash
php artisan make:mcp-tool GatewayLogsTool --no-interaction
```

Replace contents with:

```php
<?php

namespace App\Mcp\Tools;

use App\Models\GatewayLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

class GatewayLogsTool extends Tool
{
    public function name(): string
    {
        return 'gateway_logs';
    }

    public function title(): string
    {
        return 'Gateway activity log';
    }

    public function description(): string
    {
        return "Read this gateway's own activity log: MCP tool calls, upstream HTTP failures, upstream OAuth steps, and rejected incoming requests. Use it to debug a connection or an OAuth flow. Secrets are redacted before storage. Newest entries first.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Maximum rows to return, 1-200.')->default(50),
            'level' => $schema->string()->enum(GatewayLog::LEVELS)->description('Only rows at this level.'),
            'category' => $schema->string()->enum(GatewayLog::CATEGORIES)->description('Only rows in this category.'),
            'connection' => $schema->string()->description('Only rows for connections whose name contains this text.'),
            'since' => $schema->string()->description('Only rows at or after this time. ISO 8601 or a phrase like "2 hours ago".'),
            'search' => $schema->string()->description('Only rows whose message contains this text.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'level' => ['sometimes', 'string', Rule::in(GatewayLog::LEVELS)],
            'category' => ['sometimes', 'string', Rule::in(GatewayLog::CATEGORIES)],
            'connection' => ['sometimes', 'string', 'max:100'],
            'since' => ['sometimes', 'string', 'max:100'],
            'search' => ['sometimes', 'string', 'max:200'],
        ], ['limit.max' => 'The limit may not be greater than 200.']);
        $query = GatewayLog::query()->with('connection')->orderByDesc('created_at')->orderByDesc('id')->limit($validated['limit'] ?? 50);
        if (isset($validated['level'])) {
            $query->where('level', $validated['level']);
        }
        if (isset($validated['category'])) {
            $query->where('category', $validated['category']);
        }
        if (isset($validated['connection'])) {
            $query->whereHas('connection', fn (Builder $connections) => $connections->where('name', 'like', '%'.$validated['connection'].'%'));
        }
        if (isset($validated['since'])) {
            try {
                $query->where('created_at', '>=', Carbon::parse($validated['since']));
            } catch (Throwable) {
                return Response::error('The since value could not be parsed. Use ISO 8601 or a phrase like "2 hours ago".');
            }
        }
        if (isset($validated['search'])) {
            $query->where('message', 'like', '%'.$validated['search'].'%');
        }

        return Response::json(['logs' => $query->get()->map(fn (GatewayLog $log): array => [
            'id' => $log->id, 'created_at' => $log->created_at->toIso8601String(), 'level' => $log->level, 'category' => $log->category,
            'connection' => $log->connection?->name, 'message' => $log->message, 'duration_ms' => $log->duration_ms, 'context' => $log->context,
        ])->all()]);
    }
}
```

- [ ] **Step 4: Register it in `UnifiedServer::boot`**

```php
    protected function boot(): void
    {
        $this->tools = [
            ...McpConnection::query()->where('enabled', true)->orderBy('id')->get()->map(fn (McpConnection $connection): ConnectionTool => new ConnectionTool($connection))->all(),
            GatewayLogsTool::class,
        ];
    }
```

Add `use App\Mcp\Tools\GatewayLogsTool;` and update `$instructions` to: `'Each server_* tool represents a connected MCP server. Call list_available_tools first, then call a discovered tool with its name and JSON arguments. Use gateway_logs to inspect this gateway\'s activity log when a connection misbehaves.'`

- [ ] **Step 5: Fix the tool-count assertions in `tests/Feature/McpGatewayTest.php`**

In the test `enabled connections appear as wrappers and disabling or deleting removes them`, change `assertJsonCount(1, 'result.tools')` to `assertJsonCount(2, 'result.tools')` and the final `assertJsonCount(0, 'result.tools')` to `assertJsonCount(1, 'result.tools')`. The `result.tools.0.name` assertion stays because connection tools come first.

In `GatewayLogsToolTest`, the first test asserts `result.tools.0.name` is `gateway_logs` because no connections exist there.

- [ ] **Step 6: Run tests**

Run: `vendor/bin/pest tests/Feature/GatewayLogsToolTest.php tests/Feature/McpGatewayTest.php`
Expected: all pass.

- [ ] **Step 7: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "Expose gateway activity log as an MCP tool" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 4: Activity log page in the dashboard

**Files:**
- Create: `app/Filament/Resources/GatewayLogs/GatewayLogResource.php`
- Create: `app/Filament/Resources/GatewayLogs/Pages/ListGatewayLogs.php`
- Create: `resources/views/filament/gateway-logs/details.blade.php`
- Test: `tests/Feature/GatewayLogResourceTest.php`

**Interfaces:**
- Consumes: `GatewayLog` (Task 1).

- [ ] **Step 1: Write the failing tests**

```php
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
    $this->get('/app/gateway-logs')->assertOk()->assertSee('Activity log');
    Livewire::test(ListGatewayLogs::class)
        ->assertCanSeeTableRecords([$error, $info])
        ->filterTable('level', 'error')->assertCanSeeTableRecords([$error])->assertCanNotSeeTableRecords([$info])
        ->resetTableFilters()->filterTable('category', 'oauth')->assertCanSeeTableRecords([$info])->assertCanNotSeeTableRecords([$error])
        ->resetTableFilters()->filterTable('mcp_connection_id', $connection->id)->assertCanSeeTableRecords([$error])->assertCanNotSeeTableRecords([$info])
        ->resetTableFilters()->searchTable('Discovered')->assertCanSeeTableRecords([$info])->assertCanNotSeeTableRecords([$error]);
    Livewire::test(ListGatewayLogs::class)->mountAction(TestAction::make('details')->table($error))->assertSee('upstream exploded')->assertSee('Sentry');
});

test('the activity log can be cleared and is read only', function () {
    logPageOwner();
    GatewayLog::factory()->count(3)->create();
    Livewire::test(ListGatewayLogs::class)->assertActionHidden('create')->callAction('clear')->assertHasNoActionErrors();
    expect(GatewayLog::count())->toBe(0);
});

test('other users cannot open the activity log', function () {
    logPageOwner();
    $this->actingAs(User::factory()->create())->get('/app/gateway-logs')->assertForbidden();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/GatewayLogResourceTest.php`
Expected: FAIL (`Class "App\Filament\Resources\GatewayLogs\Pages\ListGatewayLogs" not found`).

- [ ] **Step 3: Generate the resource**

```bash
php artisan make:filament-resource GatewayLog --simple --no-interaction
```

If the generator produces `Manage...` pages, delete them and create the files below by hand. Keep the namespace `App\Filament\Resources\GatewayLogs`.

- [ ] **Step 4: Write the resource**

```php
<?php

namespace App\Filament\Resources\GatewayLogs;

use App\Filament\Resources\GatewayLogs\Pages\ListGatewayLogs;
use App\Models\GatewayLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class GatewayLogResource extends Resource
{
    protected static ?string $model = GatewayLog::class;

    protected static ?string $modelLabel = 'log entry';

    protected static ?string $pluralModelLabel = 'activity log';

    protected static ?string $navigationLabel = 'Activity log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->poll('10s')->columns([
            TextColumn::make('created_at')->label('Time')->since()->tooltip(fn (GatewayLog $record): string => $record->created_at->toDateTimeString().' UTC')->sortable(),
            TextColumn::make('level')->badge()->color(fn (string $state): string => match ($state) {
                'error' => 'danger',
                'warning' => 'warning',
                default => 'gray',
            }),
            TextColumn::make('category')->badge()->color('info'),
            TextColumn::make('connection.name')->label('Connection')->placeholder('—'),
            TextColumn::make('message')->searchable()->wrap(),
        ])->filters([
            SelectFilter::make('level')->options(array_combine(GatewayLog::LEVELS, GatewayLog::LEVELS)),
            SelectFilter::make('category')->options(array_combine(GatewayLog::CATEGORIES, GatewayLog::CATEGORIES)),
            SelectFilter::make('mcp_connection_id')->label('Connection')->relationship('connection', 'name'),
            Filter::make('created_at')->schema([
                DateTimePicker::make('from'),
                DateTimePicker::make('until'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, string $from): Builder => $query->where('created_at', '>=', $from))
                ->when($data['until'] ?? null, fn (Builder $query, string $until): Builder => $query->where('created_at', '<=', $until))),
        ])->recordActions([
            Action::make('details')->label('Details')->icon(Heroicon::OutlinedEye)
                ->modalHeading(fn (GatewayLog $record): string => $record->message)
                ->modalContent(fn (GatewayLog $record): View => view('filament.gateway-logs.details', ['record' => $record]))
                ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListGatewayLogs::route('/')];
    }
}
```

- [ ] **Step 5: Write the list page**

```php
<?php

namespace App\Filament\Resources\GatewayLogs\Pages;

use App\Filament\Resources\GatewayLogs\GatewayLogResource;
use App\Models\GatewayLog;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListGatewayLogs extends ListRecords
{
    protected static string $resource = GatewayLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('clear')->label('Clear logs')->color('danger')->requiresConfirmation()
                ->modalDescription('Deletes every activity log entry. This cannot be undone.')
                ->action(function (): void {
                    GatewayLog::query()->delete();
                    Notification::make()->success()->title('Activity log cleared')->send();
                }),
        ];
    }
}
```

- [ ] **Step 6: Write the details view**

```blade
<div style="display: grid; gap: 0.75rem; font-size: 0.875rem;">
    <dl style="display: grid; grid-template-columns: max-content 1fr; gap: 0.25rem 1rem;">
        <dt style="font-weight: 600;">Time</dt><dd>{{ $record->created_at->toDateTimeString() }} UTC</dd>
        <dt style="font-weight: 600;">Level</dt><dd>{{ $record->level }}</dd>
        <dt style="font-weight: 600;">Category</dt><dd>{{ $record->category }}</dd>
        <dt style="font-weight: 600;">Connection</dt><dd>{{ $record->connection?->name ?? '—' }}</dd>
        @if ($record->duration_ms !== null)
            <dt style="font-weight: 600;">Duration</dt><dd>{{ $record->duration_ms }} ms</dd>
        @endif
    </dl>
    <pre style="white-space: pre-wrap; word-break: break-word; font-size: 0.75rem; padding: 0.75rem; border-radius: 0.5rem; background: rgba(128, 128, 128, 0.12); max-height: 60vh; overflow: auto;">{{ json_encode($record->context ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
</div>
```

- [ ] **Step 7: Run tests**

Run: `vendor/bin/pest tests/Feature/GatewayLogResourceTest.php`
Expected: 3 passed. If `mountAction` cannot find the table action, use `->callAction(TestAction::make('details')->table($error))` and assert the rendered HTML with `->assertSee(...)` after `mountAction`; see Filament 5 action testing docs via `search-docs` if needed.

- [ ] **Step 8: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "Add activity log page to the dashboard" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 5: Edit connections

**Files:**
- Create: `app/Services/ConnectionEditor.php`
- Modify: `app/Filament/Resources/McpConnections/McpConnectionResource.php`
- Test: `tests/Feature/EditConnectionTest.php`

**Interfaces:**
- Produces: `ConnectionEditor::update(McpConnection $connection, array $data): McpConnection` where `$data` has keys `name`, `url`, `auth_type`, and optional `credentials` sub-array with `client_id`, `client_secret`, `scope`, `bearer_token`, `headers`.

- [ ] **Step 1: Write the failing tests**

```php
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
    expect($fresh->credentials)->toBe(['client_id' => 'cid', 'client_secret' => 'csecret', 'scope' => 'read', 'bearer_token' => 'bt']);
    Http::assertNothingSent();
});

test('editing validates the URL', function () {
    editOwner();
    $connection = McpConnection::factory()->create();
    Http::preventStrayRequests();
    Livewire::test(ManageMcpConnections::class)->callAction(TestAction::make('edit')->table($connection), data: ['name' => 'X', 'url' => 'file:///etc/passwd', 'auth_type' => 'none'])->assertHasActionErrors(['url']);
    Http::assertNothingSent();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/EditConnectionTest.php`
Expected: FAIL (no `edit` table action).

- [ ] **Step 3: Write `ConnectionEditor`**

```bash
php artisan make:class Services/ConnectionEditor --no-interaction
```

```php
<?php

namespace App\Services;

use App\Models\McpConnection;
use Illuminate\Support\Arr;

class ConnectionEditor
{
    private const FORM_CREDENTIAL_KEYS = ['client_id', 'client_secret', 'scope', 'bearer_token', 'headers'];

    private const SESSION_CREDENTIAL_KEYS = ['access_token', 'refresh_token', 'expires_at', 'metadata'];

    public function __construct(private FaviconFetcher $favicons) {}

    /**
     * Apply dashboard edits, merging form credentials into stored credentials.
     *
     * @param  array{name: string, url: string, auth_type: string, credentials?: array<string, mixed>}  $data
     */
    public function update(McpConnection $connection, array $data): McpConnection
    {
        $credentials = $connection->credentials ?? [];
        foreach (self::FORM_CREDENTIAL_KEYS as $key) {
            $value = $data['credentials'][$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                unset($credentials[$key]);
            } else {
                $credentials[$key] = $value;
            }
        }
        $attributes = Arr::only($data, ['name', 'url', 'auth_type']);
        if ($data['url'] !== $connection->url || $data['auth_type'] !== $connection->auth_type) {
            $credentials = Arr::except($credentials, self::SESSION_CREDENTIAL_KEYS);
            $attributes['status'] = $data['auth_type'] === 'oauth' ? 'Authorization required' : 'Not checked';
        }
        if ($data['url'] !== $connection->url) {
            $attributes['favicon'] = $this->favicons->fetch($data['url']);
        }
        $connection->update([...$attributes, 'credentials' => $credentials]);

        return $connection;
    }
}
```

- [ ] **Step 4: Add the `EditAction` to the resource table**

In `McpConnectionResource::table`, insert before the `check` action:

```php
            EditAction::make()
                ->mutateRecordDataUsing(function (array $data, McpConnection $record): array {
                    $data['credentials'] = Arr::only($record->credentials ?? [], ['client_id', 'client_secret', 'scope', 'bearer_token', 'headers']);

                    return $data;
                })
                ->using(fn (McpConnection $record, array $data): McpConnection => app(ConnectionEditor::class)->update($record, $data)),
```

Add imports: `use App\Services\ConnectionEditor;`, `use Filament\Actions\EditAction;`, `use Illuminate\Support\Arr;`.

The `mutateRecordDataUsing` step is required because `McpConnection::$hidden` excludes `credentials` from the array Filament fills the form with.

- [ ] **Step 5: Run tests**

Run: `vendor/bin/pest tests/Feature/EditConnectionTest.php tests/Feature/McpGatewayTest.php`
Expected: all pass. If `assertSchemaStateSet` does not exist in this Filament version, use `assertActionDataSet` after `mountAction`.

- [ ] **Step 6: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "Allow editing MCP connections without losing OAuth tokens" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 6: Upstream OAuth fixes and OAuth/auth logging

**Files:**
- Modify: `app/Services/UpstreamOAuth.php`
- Modify: `app/Http/Controllers/UpstreamOAuthController.php`
- Modify: `app/Http/Middleware/AuthenticateGateway.php`
- Test: `tests/Feature/UpstreamOAuthTest.php` (append), `tests/Feature/GatewayLoggingTest.php` (append)

**Interfaces:**
- Consumes: `ActivityLogger` (Task 1).

- [ ] **Step 1: Append failing tests to `tests/Feature/UpstreamOAuthTest.php`**

```php
test('discovery accepts unquoted resource_metadata and logs the discovered server', function () {
    $this->actingAs(User::factory()->create());
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth']);
    Http::preventStrayRequests();
    Http::fake([
        'https://mcp.example.com/mcp' => Http::response([], 401, ['WWW-Authenticate' => 'Bearer resource_metadata=https://mcp.example.com/metadata']),
        'https://mcp.example.com/metadata' => Http::response(['authorization_servers' => ['https://auth.example.com/'], 'scopes_supported' => ['tools:read']]),
        'https://auth.example.com/.well-known/oauth-authorization-server' => Http::response(['issuer' => 'https://auth.example.com', 'authorization_endpoint' => 'https://auth.example.com/authorize', 'token_endpoint' => 'https://auth.example.com/token', 'registration_endpoint' => 'https://auth.example.com/register', 'code_challenge_methods_supported' => ['S256']]),
        'https://auth.example.com/register' => Http::response(['client_id' => 'registered-client']),
    ]);
    $this->post(route('upstream.connect', $connection))->assertRedirectContains('https://auth.example.com/authorize');
    $log = App\Models\GatewayLog::where('category', 'oauth')->sole();
    expect($log->level)->toBe('info')->and($log->message)->toBe('Discovered OAuth server for '.$connection->name);
    expect($log->context)->toMatchArray(['resource_metadata_url' => 'https://mcp.example.com/metadata', 'issuer' => 'https://auth.example.com/', 'registration' => 'dynamic', 'scope' => 'tools:read']);
});

test('servers without protected resource metadata fall back to the MCP origin as issuer', function () {
    $this->actingAs(User::factory()->create());
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth', 'credentials' => ['client_id' => 'manual']]);
    Http::preventStrayRequests();
    Http::fake([
        'https://mcp.example.com/mcp' => Http::response([], 401, ['WWW-Authenticate' => 'Bearer realm="OAuth"']),
        'https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => Http::response('Not found', 404),
        'https://mcp.example.com/.well-known/oauth-protected-resource' => Http::response('Not found', 404),
        'https://mcp.example.com/.well-known/oauth-authorization-server' => Http::response(['issuer' => 'https://mcp.example.com', 'authorization_endpoint' => 'https://mcp.example.com/authorize', 'token_endpoint' => 'https://mcp.example.com/token', 'code_challenge_methods_supported' => ['S256']]),
    ]);
    $this->post(route('upstream.connect', $connection))->assertRedirectContains('https://mcp.example.com/authorize');
    expect(App\Models\GatewayLog::where('category', 'oauth')->sole()->context)->toMatchArray(['resource_metadata_url' => null, 'issuer' => 'https://mcp.example.com', 'registration' => 'manual']);
});

test('servers without dynamic registration explain that a client ID is needed', function () {
    $this->actingAs(User::factory()->create());
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth']);
    Http::preventStrayRequests();
    Http::fake([
        'https://mcp.example.com/mcp' => Http::response([], 401, ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.example.com/metadata"']),
        'https://mcp.example.com/metadata' => Http::response(['authorization_servers' => ['https://auth.example.com']]),
        'https://auth.example.com/.well-known/oauth-authorization-server' => Http::response(['issuer' => 'https://auth.example.com', 'authorization_endpoint' => 'https://auth.example.com/authorize', 'token_endpoint' => 'https://auth.example.com/token', 'code_challenge_methods_supported' => ['S256']]),
    ]);
    $this->post(route('upstream.connect', $connection))->assertRedirect(route('filament.app.resources.mcp-connections.index'));
    $log = App\Models\GatewayLog::where('level', 'error')->sole();
    expect($log->message)->toBe('OAuth discovery failed: This server does not support dynamic registration. Edit the connection and enter a client ID and secret.');
    expect(session('filament.notifications'))->not->toBeEmpty();
});

test('metadata failures name the URL and status', function () {
    $this->actingAs(User::factory()->create());
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth']);
    Http::preventStrayRequests();
    Http::fake([
        'https://mcp.example.com/mcp' => Http::response([], 401, ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.example.com/metadata"']),
        'https://mcp.example.com/metadata' => Http::response(['authorization_servers' => ['https://auth.example.com']]),
        'https://auth.example.com/*' => Http::response('nope', 503),
    ]);
    $this->post(route('upstream.connect', $connection))->assertRedirect();
    expect(App\Models\GatewayLog::where('level', 'error')->sole()->message)->toContain('https://auth.example.com/.well-known/oauth-authorization-server', 'HTTP 503');
});

test('token exchange outcomes are logged without secrets', function () {
    $this->freezeTime();
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth', 'credentials' => ['client_id' => 'client', 'access_token' => 'expired', 'refresh_token' => 'old-refresh', 'expires_at' => now()->subMinute()->timestamp, 'metadata' => ['token_endpoint' => 'https://auth.example.com/token']]]);
    Http::preventStrayRequests();
    Http::fake(['https://auth.example.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'expired', 'secret' => 'sensitive'], 400)]);
    expect(fn () => app(UpstreamOAuth::class)->refresh($connection))->toThrow(RuntimeException::class);
    $log = App\Models\GatewayLog::sole();
    expect($log->level)->toBe('error')->and($log->message)->toBe('OAuth refresh_token failed for '.$connection->name);
    expect($log->context)->toBe(['grant_type' => 'refresh_token', 'status' => 400, 'error' => 'invalid_grant', 'error_description' => 'expired']);
    expect(json_encode($log->context))->not->toContain('sensitive', 'old-refresh');
});

test('callback receipt and decline are logged and the notification carries the reason', function () {
    $this->actingAs(User::factory()->create());
    $connection = McpConnection::factory()->create(['auth_type' => 'oauth']);
    fakeUpstreamOAuth();
    $response = $this->post(route('upstream.connect', $connection))->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    $this->get(route('upstream.callback').'?'.http_build_query(['state' => $query['state'], 'error' => 'access_denied', 'error_description' => 'User said no']))->assertRedirect();
    $messages = App\Models\GatewayLog::where('category', 'oauth')->orderBy('id')->pluck('message')->all();
    expect($messages)->toContain('OAuth callback received for '.$connection->name);
    expect(end($messages))->toBe('OAuth connection failed: Authorization was declined: access_denied (User said no)');
    $this->get(route('upstream.callback').'?state='.str_repeat('b', 64).'&code=x')->assertForbidden();
    expect(App\Models\GatewayLog::where('level', 'warning')->sole()->message)->toBe('OAuth callback rejected');
});
```

- [ ] **Step 2: Append a failing test to `tests/Feature/GatewayLoggingTest.php`**

```php
test('rejected gateway requests are logged', function () {
    loggingOwner();
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])->assertUnauthorized();
    $this->withToken('wrong')->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])->assertUnauthorized();
    $logs = GatewayLog::where('category', 'auth')->orderBy('id')->get();
    expect($logs)->toHaveCount(2);
    expect($logs[0]->context)->toMatchArray(['has_bearer' => false, 'reason' => 'unauthenticated']);
    expect($logs[1]->context)->toMatchArray(['has_bearer' => true, 'reason' => 'unauthenticated'])->toHaveKey('ip');
    expect($logs->pluck('level')->unique()->all())->toBe(['warning']);
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/UpstreamOAuthTest.php tests/Feature/GatewayLoggingTest.php`
Expected: the new tests FAIL.

- [ ] **Step 4: Rewrite `UpstreamOAuth`**

```php
<?php

namespace App\Services;

use App\Models\McpConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class UpstreamOAuth
{
    public function __construct(private ActivityLogger $logger) {}

    private function http(): PendingRequest
    {
        return Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(15);
    }

    /** @return array<string, mixed> */
    private function metadata(string $url): array
    {
        $response = $this->http()->get(RemoteUrl::validate($url));
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('OAuth metadata could not be loaded from '.$url.' (HTTP '.$response->status().').');
        }

        return $response->json();
    }

    public function authorize(McpConnection $connection): string
    {
        $credentials = $connection->credentials ?? [];
        $origin = RemoteUrl::origin($connection->url);
        $probe = $this->http()->withHeaders($credentials['headers'] ?? [])->withHeaders(['Accept' => 'application/json, text/event-stream'])->post($connection->url, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'personal-mcp', 'version' => '1.0.0']],
        ]);
        preg_match('/resource_metadata="?([^",\s]+)"?/', $probe->header('WWW-Authenticate'), $match);
        $resource = null;
        $resourceUrl = null;
        $candidates = array_unique(array_filter([$match[1] ?? null, $origin.'/.well-known/oauth-protected-resource'.(parse_url($connection->url, PHP_URL_PATH) ?: ''), $origin.'/.well-known/oauth-protected-resource']));
        foreach ($candidates as $candidate) {
            try {
                $resource = $this->metadata($candidate);
                $resourceUrl = $candidate;
                break;
            } catch (RuntimeException) {
                continue;
            }
        }
        $issuer = $resource === null ? $origin : ($resource['authorization_servers'][0] ?? throw new RuntimeException('Protected resource metadata at '.$resourceUrl.' lists no authorization server.'));
        $issuerOrigin = RemoteUrl::origin($issuer);
        $issuerPath = rtrim(parse_url($issuer, PHP_URL_PATH) ?: '', '/');
        $metadata = null;
        $failures = [];
        foreach (array_unique([$issuerOrigin.'/.well-known/oauth-authorization-server'.$issuerPath, $issuerOrigin.'/.well-known/openid-configuration'.$issuerPath, rtrim($issuer, '/').'/.well-known/openid-configuration']) as $url) {
            try {
                $metadata = $this->metadata($url);
                break;
            } catch (RuntimeException $exception) {
                $failures[] = $exception->getMessage();
            }
        }
        if (! $metadata) {
            throw new RuntimeException('Authorization server metadata for '.$issuer.' could not be loaded. '.implode(' ', $failures));
        }
        if (rtrim($metadata['issuer'] ?? '', '/') !== rtrim($issuer, '/')) {
            throw new RuntimeException('Authorization server metadata issuer "'.($metadata['issuer'] ?? '').'" does not match the advertised issuer "'.$issuer.'".');
        }
        foreach (['authorization_endpoint', 'token_endpoint'] as $key) {
            RemoteUrl::validate($metadata[$key] ?? '');
        }
        if (isset($metadata['code_challenge_methods_supported']) && ! in_array('S256', $metadata['code_challenge_methods_supported'], true)) {
            throw new RuntimeException('The OAuth server must support PKCE S256.');
        }
        $redirect = route('upstream.callback');
        $registration = 'manual';
        if (empty($credentials['client_id'])) {
            if (empty($metadata['registration_endpoint'])) {
                throw new RuntimeException('This server does not support dynamic registration. Edit the connection and enter a client ID and secret.');
            }
            $registered = $this->http()->post(RemoteUrl::validate($metadata['registration_endpoint']), [
                'client_name' => config('app.name'), 'redirect_uris' => [$redirect], 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'token_endpoint_auth_method' => 'none',
            ]);
            if (! $registered->successful() || ! is_string($registered->json('client_id'))) {
                $this->logger->error('oauth', 'Dynamic registration failed for '.$connection->name, ['url' => $metadata['registration_endpoint'], 'status' => $registered->status(), 'body' => $registered->body()], $connection);
                throw new RuntimeException('Dynamic registration at '.$metadata['registration_endpoint'].' failed (HTTP '.$registered->status().'). Supply a client ID and secret if required.');
            }
            $credentials['client_id'] = $registered->json('client_id');
            $credentials['client_secret'] = $registered->json('client_secret');
            $registration = 'dynamic';
        }
        $credentials['metadata'] = $metadata;
        $connection->update(['credentials' => $credentials]);
        $state = Str::random(64);
        $verifier = Str::random(96);
        session()->put('upstream.'.$connection->id, ['state' => $state, 'verifier' => $verifier, 'expires' => now()->addMinutes(10)->timestamp]);
        session()->put('upstream-states.'.$state, $connection->id);
        $query = [
            'client_id' => $credentials['client_id'], 'redirect_uri' => $redirect, 'response_type' => 'code', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256', 'resource' => $connection->url,
        ];
        $scope = $credentials['scope'] ?? implode(' ', $resource['scopes_supported'] ?? []);
        if ($scope !== '') {
            $query['scope'] = $scope;
        }
        $this->logger->info('oauth', 'Discovered OAuth server for '.$connection->name, [
            'resource_metadata_url' => $resourceUrl, 'issuer' => $issuer, 'authorization_endpoint' => $metadata['authorization_endpoint'], 'token_endpoint' => $metadata['token_endpoint'],
            'registration' => $registration, 'scope' => $scope, 'redirect_uri' => $redirect,
        ], $connection);

        return $metadata['authorization_endpoint'].(str_contains($metadata['authorization_endpoint'], '?') ? '&' : '?').http_build_query($query);
    }

    public function exchange(McpConnection $connection, string $code, string $verifier): void
    {
        $this->token($connection, ['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => route('upstream.callback')]);
    }

    public function refresh(McpConnection $connection): void
    {
        $credentials = $connection->credentials ?? [];
        if (empty($credentials['access_token'])) {
            throw new RuntimeException('Connect this server with OAuth first.');
        }
        if (empty($credentials['expires_at']) || $credentials['expires_at'] > now()->addSeconds(30)->timestamp) {
            return;
        }
        Cache::lock('oauth-refresh-'.$connection->id, 30)->block(10, function () use ($connection): void {
            $connection->refresh();
            $credentials = $connection->credentials;
            if (($credentials['expires_at'] ?? PHP_INT_MAX) > now()->addSeconds(30)->timestamp) {
                return;
            }
            if (empty($credentials['refresh_token'])) {
                throw new RuntimeException('OAuth expired. Reconnect this server.');
            }
            $this->token($connection, ['grant_type' => 'refresh_token', 'refresh_token' => $credentials['refresh_token']]);
        });
    }

    /** @param array<string, string> $params */
    private function token(McpConnection $connection, array $params): void
    {
        $credentials = $connection->credentials;
        $params += ['client_id' => $credentials['client_id'], 'resource' => $connection->url];
        $http = $this->http()->asForm();
        if (! empty($credentials['client_secret'])) {
            $methods = $credentials['metadata']['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
            if (in_array('client_secret_basic', $methods, true)) {
                $http = $http->withBasicAuth($credentials['client_id'], $credentials['client_secret']);
            } else {
                $params['client_secret'] = $credentials['client_secret'];
            }
        }
        $response = $http->post(RemoteUrl::validate($credentials['metadata']['token_endpoint']), $params);
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            $this->logger->error('oauth', 'OAuth '.$params['grant_type'].' failed for '.$connection->name, [
                'grant_type' => $params['grant_type'], 'status' => $response->status(), 'error' => $response->json('error'), 'error_description' => $response->json('error_description'),
            ], $connection);
            $connection->update(['status' => 'Reconnect required']);
            throw new RuntimeException('OAuth token exchange failed. Reconnect this server.');
        }
        $credentials['access_token'] = $response->json('access_token');
        $credentials['refresh_token'] = $response->json('refresh_token') ?? ($credentials['refresh_token'] ?? null);
        $credentials['expires_at'] = $response->json('expires_in') ? now()->addSeconds((int) $response->json('expires_in'))->timestamp : null;
        $connection->update(['credentials' => $credentials, 'status' => 'Connected']);
        $this->logger->info('oauth', 'OAuth '.$params['grant_type'].' succeeded for '.$connection->name, [
            'grant_type' => $params['grant_type'], 'expires_in' => $response->json('expires_in'), 'has_refresh_token' => ! empty($credentials['refresh_token']),
        ], $connection);
    }
}
```

Note the token-failure context deliberately omits the response body so upstream secrets never reach the table; the existing test `rejected refresh tokens mark the connection...` still expects the generic exception message.

- [ ] **Step 5: Rewrite `UpstreamOAuthController`**

```php
<?php

namespace App\Http\Controllers;

use App\Filament\Resources\McpConnections\McpConnectionResource;
use App\Models\McpConnection;
use App\Services\ActivityLogger;
use App\Services\UpstreamOAuth;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class UpstreamOAuthController extends Controller
{
    public function __construct(private ActivityLogger $logger) {}

    public function connect(McpConnection $connection, UpstreamOAuth $oauth): RedirectResponse
    {
        abort_unless($connection->auth_type === 'oauth', 404);
        try {
            return redirect()->away($oauth->authorize($connection));
        } catch (Throwable $exception) {
            $this->logger->error('oauth', 'OAuth discovery failed: '.$exception->getMessage(), ['exception' => $exception::class], $connection);
            Notification::make()->danger()->title('OAuth discovery failed')->body($exception->getMessage())->persistent()->send();

            return redirect(McpConnectionResource::getUrl());
        }
    }

    public function callback(Request $request, UpstreamOAuth $oauth): RedirectResponse
    {
        $state = $request->query('state');
        $connectionId = is_string($state) && preg_match('/^[A-Za-z0-9]{64}$/', $state) ? $request->session()->pull('upstream-states.'.$state) : null;
        $connection = is_int($connectionId) ? McpConnection::find($connectionId) : null;
        $pending = $connection ? $request->session()->pull('upstream.'.$connection->id) : null;
        if (! $connection || ! is_array($pending) || ! hash_equals($pending['state'], $state) || $pending['expires'] < now()->timestamp) {
            $this->logger->warning('oauth', 'OAuth callback rejected', ['reason' => $connection ? 'state mismatch or expired' : 'unknown state'], $connection);
            abort(403);
        }
        $this->logger->info('oauth', 'OAuth callback received for '.$connection->name, [
            'has_code' => is_string($request->query('code')), 'error' => $request->query('error'), 'error_description' => $request->query('error_description'),
        ], $connection);
        try {
            if ($request->has('error') || ! is_string($request->query('code'))) {
                $reason = $request->query('error') ? ': '.$request->query('error').($request->query('error_description') ? ' ('.$request->query('error_description').')' : '') : '';
                throw new RuntimeException('Authorization was declined'.$reason);
            }
            $oauth->exchange($connection, $request->query('code'), $pending['verifier']);
            Notification::make()->success()->title('MCP connected')->send();
        } catch (Throwable $exception) {
            $this->logger->error('oauth', 'OAuth connection failed: '.$exception->getMessage(), ['exception' => $exception::class], $connection);
            Notification::make()->danger()->title('OAuth connection failed')->body($exception->getMessage())->persistent()->send();
        }

        return redirect(McpConnectionResource::getUrl());
    }
}
```

- [ ] **Step 6: Log rejections in `AuthenticateGateway`**

```php
<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateGateway
{
    public function __construct(private ActivityLogger $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        $owner = User::query()->orderBy('id')->first();
        $token = $request->bearerToken() ?: ($owner?->gateway_header ? $request->header($owner->gateway_header) : null);
        if ($owner?->gateway_token_hash && is_string($token) && hash_equals($owner->gateway_token_hash, hash('sha256', $token))) {
            Auth::setUser($owner);
            $request->setUserResolver(fn (): User => $owner);

            return $next($request);
        }
        $user = Auth::guard('api')->user();
        if (! $user || ! $user->isOwner()) {
            $this->reject($request, $owner, 'unauthenticated');

            return response()->json(['error' => 'Unauthenticated'], 401)->header('WWW-Authenticate', 'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
        }
        if (! $user->tokenCan('mcp:use')) {
            $this->reject($request, $owner, 'missing mcp:use scope');
            abort(403);
        }
        Auth::setUser($user);
        $request->setUserResolver(fn (): User => $user);

        return $next($request);
    }

    private function reject(Request $request, ?User $owner, string $reason): void
    {
        $this->logger->warning('auth', 'Gateway request rejected', [
            'ip' => $request->ip(), 'reason' => $reason, 'has_bearer' => is_string($request->bearerToken()),
            'has_custom_header' => (bool) ($owner?->gateway_header && $request->hasHeader($owner->gateway_header)),
        ]);
    }
}
```

- [ ] **Step 7: Run tests**

Run: `vendor/bin/pest tests/Feature/UpstreamOAuthTest.php tests/Feature/GatewayLoggingTest.php tests/Feature/McpGatewayTest.php`
Expected: all pass, including the pre-existing `OAuth callback rejects missing expired mismatched and replayed state` test.

- [ ] **Step 8: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "Surface upstream OAuth failures and log OAuth and auth events" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 7: Full suite, rule record, and manual check

**Files:**
- Modify: `.ai/rules/app.md` (via `record-rule`)

- [ ] **Step 1: Run the full suite**

Run: `php artisan test --compact`
Expected: all pass.

- [ ] **Step 2: Record the durable rule**

Use the Boost `record-rule` tool with glob `app/**`, title `Activity log`, note: `All gateway activity (tool calls, upstream failures, OAuth steps, rejected /mcp requests) goes through App\Services\ActivityLogger, which redacts secret keys and truncates strings before writing gateway_logs. Never write GatewayLog rows directly and never put upstream response bodies in Filament notifications; only exception messages. The gateway_logs MCP tool and the Activity log page read the same table.`

- [ ] **Step 3: Boot the app and check the pages**

```bash
php artisan migrate --no-interaction
php artisan serve --port=8000
```

Open `/app/gateway-logs` and `/app/mcp-connections`, confirm the Edit action opens with existing values and the Activity log page renders, filters, and shows details.

- [ ] **Step 4: Commit anything outstanding**

```bash
git status
```
