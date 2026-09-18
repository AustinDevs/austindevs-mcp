<?php

namespace App\Services;

use App\Models\McpConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RemoteMcpClient
{
    private ?string $sessionId = null;

    private string $protocol = '2025-06-18';

    private int $requestId = 0;

    public function __construct(private McpConnection $connection) {}

    public function initialize(): void
    {
        $result = $this->rpc('initialize', ['protocolVersion' => $this->protocol, 'capabilities' => (object) [], 'clientInfo' => ['name' => 'personal-mcp', 'version' => '1.0.0']]);
        $this->protocol = $result['protocolVersion'] ?? $this->protocol;
        $this->rpc('notifications/initialized', [], true);
    }

    public function tools(): array
    {
        $tools = [];
        $cursor = null;
        for ($page = 0; $page < 100; $page++) {
            $result = $this->rpc('tools/list', $cursor ? ['cursor' => $cursor] : []);
            $tools = array_merge($tools, $result['tools'] ?? []);
            $cursor = $result['nextCursor'] ?? null;
            if (! $cursor) {
                return $tools;
            }
        }
        throw new RuntimeException('Upstream tool pagination exceeded its limit.');
    }

    public function call(string $name, array $arguments): array
    {
        return $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
    }

    private function rpc(string $method, array $params, bool $notification = false): array
    {
        $this->connection->refresh();
        if (! $this->connection->enabled) {
            throw new RuntimeException('This connection is disabled.');
        }
        if ($this->connection->auth_type === 'oauth') {
            app(UpstreamOAuth::class)->refresh($this->connection);
        }
        $credentials = $this->connection->credentials ?? [];
        $headers = $credentials['headers'] ?? [];
        $token = $this->connection->auth_type === 'oauth' ? ($credentials['access_token'] ?? null) : ($credentials['bearer_token'] ?? null);
        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }
        $headers['Accept'] = 'application/json, text/event-stream';
        $headers['MCP-Protocol-Version'] = $this->protocol;
        if ($this->sessionId) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }
        $id = ++$this->requestId;
        $payload = ['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params];
        if (! $notification) {
            $payload['id'] = $id;
        }
        $response = Http::withHeaders($headers)->withoutRedirecting()->connectTimeout(5)->timeout(60)
            ->post(RemoteUrl::validate($this->connection->url), $payload);
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
    }
}
