<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ConnectionTool;
use App\Models\McpConnection;
use Laravel\Mcp\Server;

class UnifiedServer extends Server
{
    protected string $name = 'Personal MCP';

    protected string $version = '1.0.0';

    protected string $instructions = 'Each tool represents a connected MCP server. Call list_available_tools first, then call a discovered tool with its name and JSON arguments.';

    protected function boot(): void
    {
        $this->tools = McpConnection::query()->where('enabled', true)->orderBy('id')->get()
            ->map(fn (McpConnection $connection): ConnectionTool => new ConnectionTool($connection))->all();
    }
}
