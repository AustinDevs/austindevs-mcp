<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mcp_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('url');
            $table->string('auth_type')->default('none');
            $table->boolean('enabled')->default(true);
            $table->text('credentials')->nullable();
            $table->text('favicon')->nullable();
            $table->string('status')->default('Not checked');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_connections');
    }
};
