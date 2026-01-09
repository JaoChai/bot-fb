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
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Evaluation configuration
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', [
                'pending',
                'generating_tests',
                'running',
                'evaluating',
                'completed',
                'failed'
            ])->default('pending');
            $table->string('judge_model')->default('anthropic/claude-3.5-sonnet');
            $table->json('personas')->nullable(); // Array of persona keys
            $table->json('config')->nullable(); // Test generation config

            // Results summary
            $table->float('overall_score')->nullable();
            $table->json('metric_scores')->nullable(); // {relevancy: 0.85, faithfulness: 0.9, ...}
            $table->json('recommendations')->nullable(); // AI-generated improvement suggestions

            // Execution metadata
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('total_test_cases')->default(0);
            $table->integer('completed_test_cases')->default(0);
            $table->integer('total_tokens_used')->default(0);
            $table->float('estimated_cost')->default(0);
            $table->text('error_message')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['bot_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }
};
