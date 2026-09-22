<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ConnectionTool;
use App\Mcp\Tools\GatewayLogsTool;
use App\Models\McpConnection;
use Laravel\Mcp\Server;

class UnifiedServer extends Server
{
    protected string $name = 'Austin Devs MCP';

    protected string $version = '1.0.0';

    protected string $instructions = 'Each connection tool is named after its connected MCP server with a unique ID suffix. Call it with tool_name set to list_available_tools first, then call it with a discovered tool_name and JSON-encoded arguments. Use gateway_logs to inspect this gateway\'s activity log when a connection misbehaves.';

    protected function boot(): void
    {
        $this->tools = [
            ...McpConnection::query()->where('enabled', true)->orderBy('id')->get()->map(fn (McpConnection $connection): ConnectionTool => new ConnectionTool($connection))->all(),
            GatewayLogsTool::class,
        ];

        $this->defaultPaginationLength = count($this->tools);
        $this->maxPaginationLength = count($this->tools);
    }
}
