<?php

namespace App\Mcp\Tools;

use App\Models\McpConnection;
use App\Services\RemoteMcpClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
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

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['tool_name' => ['required', 'string'], 'arguments' => ['sometimes', 'string', 'json']]);
        $arguments = json_decode($validated['arguments'] ?? '{}');
        if (! $arguments instanceof \stdClass) {
            return Response::error('Arguments must be a JSON object.');
        }
        try {
            $client = new RemoteMcpClient($this->connection);
            $client->initialize();
            $result = $validated['tool_name'] === 'list_available_tools' ? ['tools' => $client->tools()] : $client->call($validated['tool_name'], (array) $arguments);
            $this->connection->update(['status' => 'Connected']);

            return ! empty($result['isError']) ? Response::error(json_encode($result, JSON_THROW_ON_ERROR)) : Response::json($result);
        } catch (Throwable) {
            if ($this->connection->exists && McpConnection::whereKey($this->connection->id)->exists()) {
                $this->connection->update(['status' => 'Connection needs attention']);
            }

            return Response::error('Upstream connection failed. Check this server in the dashboard; reconnect if authentication expired.');
        }
    }
}
