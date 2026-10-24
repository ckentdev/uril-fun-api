<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('test'); // test | welcome
            // Privacy: only a masked address is stored, never the body.
            $table->string('recipient_masked', 255);
            $table->string('subject', 150);
            $table->string('status', 20); // queued | accepted | failed
            $table->string('mailer', 30)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
