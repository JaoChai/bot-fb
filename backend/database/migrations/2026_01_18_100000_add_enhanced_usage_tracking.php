<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds enhanced usage tracking fields for OpenRouter best practices:
     * - actual_cost: Real cost from OpenRouter API (vs estimated)
     * - cached_tokens: Tokens served from prompt cache (cheaper pricing)
     * - reasoning_tokens: Tokens used by reasoning models (o1, deepseek-r1)
     * - reasoning_content: The reasoning/thinking content from AI
     */
    public function up(): void
    {
        // Add enhanced fields to agent_cost_usage table
        Schema::table('agent_cost_usage', function (Blueprint $table) {
            // Actual cost from OpenRouter API response (may differ from estimated)
            $table->decimal('actual_cost', 10, 6)->nullable()->after('estimated_cost');

            // Cache tokens from prompt caching (charged at reduced rate)
            $table->integer('cached_tokens')->default(0)->after('completion_tokens');

            // Reasoning tokens from o1/deepseek-r1 models
            $table->integer('reasoning_tokens')->default(0)->after('cached_tokens');
        });

        // Add enhanced fields to messages table
        Schema::table('messages', function (Blueprint $table) {
            // Cache tokens for this specific message
            $table->integer('cached_tokens')->nullable()->after('completion_tokens');

            // Reasoning tokens for this specific message
            $table->integer('reasoning_tokens')->nullable()->after('cached_tokens');

            // Reasoning/thinking content from AI (for o1, deepseek-r1)
            $table->text('reasoning_content')->nullable()->after('content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_cost_usage', function (Blueprint $table) {
            $table->dropColumn(['actual_cost', 'cached_tokens', 'reasoning_tokens']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['cached_tokens', 'reasoning_tokens', 'reasoning_content']);
        });
    }
};
