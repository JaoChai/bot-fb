<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds safety-related fields for agentic mode:
     * - Timeout mechanism
     * - Cost limiting
     * - Human-in-the-loop (HITL) approval
     */
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            // Timeout settings
            $table->integer('agent_timeout_seconds')
                ->default(120)
                ->after('max_tool_calls')
                ->comment('Max duration for agent loop in seconds');

            // Cost limiting
            $table->decimal('agent_max_cost_per_request', 8, 4)
                ->nullable()
                ->after('agent_timeout_seconds')
                ->comment('Max cost in USD per request, null = unlimited');

            // Human-in-the-loop settings
            $table->boolean('hitl_enabled')
                ->default(false)
                ->after('agent_max_cost_per_request')
                ->comment('Enable HITL approval for dangerous actions');

            $table->json('hitl_dangerous_actions')
                ->nullable()
                ->after('hitl_enabled')
                ->comment('List of tool names requiring approval');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn([
                'agent_timeout_seconds',
                'agent_max_cost_per_request',
                'hitl_enabled',
                'hitl_dangerous_actions',
            ]);
        });
    }
};
