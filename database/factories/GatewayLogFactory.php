<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class GatewayLogFactory extends Factory
{
    public function definition(): array
    {
        return ['level' => 'info', 'category' => 'tool_call', 'message' => fake()->sentence(), 'context' => null, 'created_at' => now()];
    }
}
