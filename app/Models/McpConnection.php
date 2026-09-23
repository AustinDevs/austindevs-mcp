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

    public function refreshTokenDeadline(): ?Carbon
    {
        $credentials = $this->credentials ?? [];
        if ($this->auth_type !== 'oauth' || empty($credentials['refresh_token'])) {
            return null;
        }
        if (isset($credentials['refresh_token_expires_at'])) {
            return Carbon::createFromTimestamp($credentials['refresh_token_expires_at']);
        }
        if (rtrim($credentials['metadata']['issuer'] ?? '', '/') === 'https://accounts.google.com'
            && isset($credentials['last_token_refresh_at'])) {
            return Carbon::createFromTimestamp($credentials['last_token_refresh_at'])->addMonthsNoOverflow(6);
        }

        return null;
    }

    public function refreshTokenLifetime(): string
    {
        if ($this->auth_type !== 'oauth') {
            return 'Not applicable';
        }
        if ($this->status === 'Reconnect required') {
            return 'Reconnect required';
        }
        if (empty($this->credentials['refresh_token'])) {
            return 'No refresh token';
        }
        $deadline = $this->refreshTokenDeadline();
        if ($deadline === null) {
            return 'Expiry not provided';
        }
        if ($deadline->lte(now())) {
            return 'Expired';
        }
        $days = (int) ceil(now()->diffInDays($deadline));
        $estimated = ! isset($this->credentials['refresh_token_expires_at']);

        return ($estimated ? '≈' : '').$days.' '.Str::plural('day', $days).' left';
    }

    public function refreshTokenDescription(): ?string
    {
        if ($this->auth_type !== 'oauth' || empty($this->credentials['refresh_token'])) {
            return null;
        }
        $policy = isset($this->credentials['refresh_token_expires_at']) ? 'Provider expiry' : ($this->refreshTokenDeadline() ? 'Google inactivity estimate' : 'Provider does not disclose expiry');

        return $policy.' · '.($this->enabled && $this->status !== 'Reconnect required' ? 'Auto-refresh enabled' : 'Auto-refresh paused');
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
