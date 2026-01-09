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
        Schema::create('improvement_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Status
            $table->string('status')->default('analyzing');

            // Original state (for rollback)
            $table->text('original_system_prompt')->nullable();
            $table->json('original_kb_snapshot')->nullable();

            // Analysis
            $table->text('analysis_summary')->nullable();

            // Score comparison
            $table->float('before_score')->nullable();
            $table->float('after_score')->nullable();
            $table->float('score_improvement')->nullable();

            // Re-evaluation link
            $table->foreignId('re_evaluation_id')->nullable()
                ->constrained('evaluations')->nullOnDelete();

            // Metadata
            $table->string('agent_model')->default('anthropic/claude-3.5-sonnet');
            $table->integer('total_tokens_used')->default(0);
            $table->float('estimated_cost')->default(0);
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('improvement_sessions');
    }
};
