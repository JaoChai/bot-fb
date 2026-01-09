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
        Schema::create('evaluation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_case_id')->constrained('evaluation_test_cases')->cascadeOnDelete();

            $table->integer('turn_number');
            $table->enum('role', ['user', 'assistant', 'system']);
            $table->text('content');

            // For assistant messages - capture RAG context
            $table->json('rag_metadata')->nullable(); // KB chunks retrieved, similarity scores
            $table->json('model_metadata')->nullable(); // Model used, tokens, latency

            // For evaluation
            $table->json('turn_scores')->nullable(); // Per-turn evaluation scores

            $table->timestamps();

            $table->index(['test_case_id', 'turn_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_messages');
    }
};
