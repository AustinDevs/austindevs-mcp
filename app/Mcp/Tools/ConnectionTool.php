<?php

namespace App\Mcp\Tools;

use App\Mcp\Content\EmbeddedResourceResponse;
use App\Models\McpConnection;
use App\Services\ActivityLogger;
use App\Services\RemoteMcpClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

class ConnectionTool extends Tool
{
    public function __construct(private McpConnection $connection) {}

    public function name(): string
    {
        return $this->connection->toolName();
    }

    public function title(): string
    {
        return $this->connection->name;
    }

    public function description(): string
    {
        return 'Access '.$this->connection->name.'. Call list_available_tools to discover its tools, then pass a tool_name and JSON-encoded arguments.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_name' => $schema->string()->required(),
            'arguments' => $schema->string()->description('JSON object of arguments for the upstream tool.')->default('{}'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
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

            if (! empty($result['isError'])) {
                return Response::error($encoded);
            }

            if ($validated['tool_name'] === 'email_pdf') {
                $pdf = collect($result['content'] ?? [])->first(fn (array $block): bool => ($block['type'] ?? null) === 'resource'
                    && ($block['resource']['mimeType'] ?? null) === 'application/pdf');
                $resource = $pdf['resource'] ?? null;

                if (is_array($resource) && isset($resource['uri'], $resource['mimeType'], $resource['blob'])
                    && is_string($resource['uri']) && is_string($resource['blob'])) {
                    $summary = collect($result['content'])->first(fn (array $block): bool => ($block['type'] ?? null) === 'text');

                    return Response::make([
                        Response::text($summary['text'] ?? 'Spark email PDF'),
                        EmbeddedResourceResponse::fromResource($resource),
                    ]);
                }
            }

            return Response::json($result);
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
}
