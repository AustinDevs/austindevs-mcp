<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        return 'server_'.$this->id;
    }
}
