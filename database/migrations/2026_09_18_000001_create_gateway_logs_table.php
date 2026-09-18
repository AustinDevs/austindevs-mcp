<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('level', 16);
            $table->string('category', 32);
            $table->foreignId('mcp_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message');
            $table->json('context')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['category', 'created_at']);
            $table->index(['level', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_logs');
    }
};
