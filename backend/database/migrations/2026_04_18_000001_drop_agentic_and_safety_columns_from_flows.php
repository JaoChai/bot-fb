<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn([
                'agentic_mode',
                'max_tool_calls',
                'enabled_tools',
                'language',
                'agent_timeout_seconds',
                'agent_max_cost_per_request',
                'hitl_enabled',
                'hitl_dangerous_actions',
                'second_ai_enabled',
                'second_ai_options',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->boolean('agentic_mode')->default(false);
            $table->integer('max_tool_calls')->default(10);
            $table->json('enabled_tools')->nullable();
            $table->string('language', 10)->default('th');
            $table->integer('agent_timeout_seconds')->default(120);
            $table->decimal('agent_max_cost_per_request', 8, 4)->nullable();
            $table->boolean('hitl_enabled')->default(false);
            $table->json('hitl_dangerous_actions')->nullable();
            $table->boolean('second_ai_enabled')->default(false);
            $table->jsonb('second_ai_options')->nullable();
        });
    }
};
