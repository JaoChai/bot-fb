<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('bot_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('type'); // evaluation_completed, handover_started, improvement_applied, etc.
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['user_id', 'created_at']);
            $table->index(['bot_id', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
