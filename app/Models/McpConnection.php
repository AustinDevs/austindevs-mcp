<?php

namespace App\Models;

use App\Services\ServiceIcon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class McpConnection extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'url', 'auth_type', 'enabled', 'credentials', 'favicon', 'status'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'credentials' => 'encrypted:array'];
    }

    public function toolName(): string
    {
        $name = Str::slug($this->name, '_');
        $suffix = '_'.$this->id;

        return substr($name !== '' ? $name : 'connection', 0, 64 - strlen($suffix)).$suffix;
    }

    public function nextTokenRefreshAt(): ?Carbon
    {
        $credentials = $this->credentials ?? [];
        if (! $this->enabled || $this->auth_type !== 'oauth' || $this->status === 'Reconnect required'
            || empty($credentials['access_token']) || empty($credentials['refresh_token'])) {
            return null;
        }
        $lastRefresh = $credentials['last_token_refresh_at'] ?? 0;
        $due = $lastRefresh + 7 * 86400;
        if (isset($credentials['expires_at'])) {
            $due = min($due, $credentials['expires_at'] - 300);
        }
        if (isset($credentials['refresh_token_expires_at'])) {
            $due = min($due, max($credentials['refresh_token_expires_at'] - 86400, $lastRefresh + 3600));
        }

        return Carbon::createFromTimestamp($due);
    }

    public function lastTokenRefreshLabel(): string
    {
        if ($this->auth_type !== 'oauth') {
            return 'Not applicable';
        }
        $timestamp = $this->credentials['last_token_refresh_at'] ?? null;

        return $timestamp ? Carbon::createFromTimestamp($timestamp)->diffForHumans() : 'Not recorded yet';
    }

    public function nextTokenRefreshLabel(): string
    {
        if ($this->auth_type !== 'oauth') {
            return 'Not applicable';
        }
        if (! $this->enabled) {
            return 'Paused';
        }
        if ($this->status === 'Reconnect required') {
            return 'Reconnect required';
        }
        $due = $this->nextTokenRefreshAt();
        if ($due === null) {
            return 'Connect to enable';
        }

        return $due->lte(now()) ? 'Next scheduled check' : $due->diffForHumans();
    }

    /**
     * The fetched favicon, else a bundled brand icon matched by name or host, else a neutral placeholder.
     */
    public function iconUrl(): string
    {
        $icons = app(ServiceIcon::class);

        return $this->favicon ?: ($icons->resolve($this->name, $this->url) ?? $icons->defaultPath());
    }
}
