<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tracks AI cost usage per request for:
     * - Daily user spending limits
     * - Cost analytics and monitoring
     * - Billing and quotas
     */
    public function up(): void
    {
        Schema::create('agent_cost_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_id')->nullable()->constrained()->nullOnDelete();

            // Request identification
            $table->uuid('request_id')->unique();

            // Cost breakdown
            $table->decimal('estimated_cost', 10, 6)->default(0);
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('tool_calls')->default(0);

            // Model info
            $table->string('model_used', 100)->nullable();
            $table->string('fallback_model_used', 100)->nullable();

            // Performance
            $table->integer('duration_ms')->default(0);
            $table->integer('iterations')->default(0);

            // Status
            $table->enum('status', [
                'completed',
                'timeout',
                'cost_limit',
                'rate_limit',
                'error',
                'cancelled',
            ])->default('completed');

            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes for querying
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index(['bot_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_cost_usage');
    }
};
