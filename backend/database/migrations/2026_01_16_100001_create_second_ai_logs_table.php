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
        Schema::create('second_ai_logs', function (Blueprint $table) {
            $table->id();

            // Foreign keys
            $table->foreignId('bot_id')
                ->constrained('bots')
                ->cascadeOnDelete();
            $table->foreignId('conversation_id')
                ->nullable()
                ->constrained('conversations')
                ->nullOnDelete();
            $table->foreignId('message_id')
                ->nullable()
                ->constrained('messages')
                ->nullOnDelete();
            $table->foreignId('flow_id')
                ->nullable()
                ->constrained('flows')
                ->nullOnDelete();

            // Score metrics (0.00 - 1.00)
            $table->decimal('groundedness_score', 3, 2)->nullable();
            $table->decimal('policy_compliance_score', 3, 2)->nullable();
            $table->decimal('personality_match_score', 3, 2)->nullable();
            $table->decimal('overall_score', 3, 2)->nullable();

            // Processing metadata
            $table->boolean('was_modified')->default(false);
            $table->jsonb('checks_applied')->nullable();
            $table->jsonb('modifications')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->string('model_used', 100)->nullable();
            $table->string('execution_mode', 20)->nullable(); // unified, sequential

            $table->timestamps();

            // Indexes for querying
            $table->index(['bot_id', 'created_at']);
            $table->index(['flow_id', 'created_at']);
            $table->index('overall_score');
            $table->index('was_modified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('second_ai_logs');
    }
};
