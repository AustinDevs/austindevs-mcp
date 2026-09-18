<?php

namespace App\Models;

use App\Services\ServiceIcon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * The fetched favicon, else a bundled brand icon matched by name or host, else a neutral placeholder.
     */
    public function iconUrl(): string
    {
        $icons = app(ServiceIcon::class);

        return $this->favicon ?: ($icons->resolve($this->name, $this->url) ?? $icons->defaultPath());
    }
}
