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
        Schema::create('injection_attempts_log', function (Blueprint $table) {
            $table->id();

            // Foreign keys
            $table->foreignId('bot_id')
                ->constrained('bots')
                ->cascadeOnDelete();
            $table->foreignId('conversation_id')
                ->nullable()
                ->constrained('conversations')
                ->nullOnDelete();

            // Detection data
            $table->text('user_input');
            $table->jsonb('detected_patterns');
            $table->decimal('risk_score', 3, 2);
            $table->string('action_taken', 20); // blocked, flagged, allowed

            $table->timestamps();

            // Indexes for querying
            $table->index(['bot_id', 'created_at']);
            $table->index('action_taken');
            $table->index('risk_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('injection_attempts_log');
    }
};
