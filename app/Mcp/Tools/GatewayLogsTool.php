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
