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
