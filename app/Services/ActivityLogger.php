<?php

namespace App\Services;

use App\Models\GatewayLog;
use App\Models\McpConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ActivityLogger
{
    private const REDACTED_KEYS = ['authorization', 'proxy-authorization', 'access_token', 'refresh_token', 'bearer_token', 'client_secret', 'code_verifier', 'token', 'secret', 'password', 'cookie', 'set-cookie'];

    private const MAX_STRING_LENGTH = 2000;

    private const STRING_PATTERNS = [
        '/(bearer\s+)[A-Za-z0-9._~+\/=-]{8,}/i',
        '/("?(?:access_token|refresh_token|client_secret|bearer_token|code_verifier|token|secret|password)"?\s*[:=]\s*"?)[^"&\s,}]+/i',
    ];

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
     * Replace secret-bearing keys and shorten long strings before anything is stored.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = $this->redact($value);
            } elseif (is_string($value)) {
                $context[$key] = Str::limit($this->scrub($value), self::MAX_STRING_LENGTH, '… [truncated]');
            }
        }

        return $context;
    }

    /**
     * Mask bearer tokens and token-like key/value pairs embedded in free text such as upstream response bodies.
     */
    private function scrub(string $value): string
    {
        return (string) preg_replace(self::STRING_PATTERNS, '$1[redacted]', $value);
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
