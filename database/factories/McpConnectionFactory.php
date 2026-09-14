<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class McpConnectionFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->company(), 'url' => 'https://mcp.example.com/mcp', 'auth_type' => 'none', 'enabled' => true];
    }
}
