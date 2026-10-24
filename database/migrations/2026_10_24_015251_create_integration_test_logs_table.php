<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_test_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('integration', 32);
            $table->string('status', 20); // connected | not_configured | error | unknown
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('summary', 255)->nullable(); // sanitized, short
            $table->timestamp('created_at')->useCurrent();

            $table->index(['integration', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_test_logs');
    }
};
