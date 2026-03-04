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
        Schema::create('evaluation_test_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->nullable()->constrained()->nullOnDelete();

            // Test case definition
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('persona_key'); // e.g., 'thai_new_customer', 'thai_frustrated'
            $table->enum('test_type', [
                'single_turn',
                'multi_turn',
                'edge_case',
                'persona_adherence',
            ])->default('single_turn');
            $table->json('expected_topics')->nullable(); // Topics the response should cover
            $table->json('source_chunks')->nullable(); // KB chunk IDs used to generate this test

            // Status tracking
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');

            // Scores (filled after evaluation) - 0.0 to 1.0
            $table->float('answer_relevancy')->nullable();
            $table->float('faithfulness')->nullable();
            $table->float('role_adherence')->nullable();
            $table->float('context_precision')->nullable();
            $table->float('task_completion')->nullable();
            $table->float('overall_score')->nullable();
            $table->json('detailed_feedback')->nullable(); // LLM judge feedback per metric

            $table->timestamps();

            $table->index(['evaluation_id', 'status']);
            $table->index('persona_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_test_cases');
    }
};
