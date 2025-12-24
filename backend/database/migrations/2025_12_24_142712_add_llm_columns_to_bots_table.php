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
        Schema::table('bots', function (Blueprint $table) {
            // LLM Configuration
            $table->string('llm_model', 100)
                ->default('anthropic/claude-3.5-sonnet')
                ->after('default_flow_id');

            $table->string('llm_fallback_model', 100)
                ->default('openai/gpt-4o-mini')
                ->after('llm_model');

            // System prompt for the bot's personality and behavior
            $table->text('system_prompt')->nullable()->after('llm_fallback_model');

            // Temperature for response randomness (0.0 - 2.0)
            $table->decimal('llm_temperature', 3, 2)->default(0.7)->after('system_prompt');

            // Max tokens per response
            $table->unsignedInteger('llm_max_tokens')->default(2048)->after('llm_temperature');

            // Context window - how many previous messages to include
            $table->unsignedSmallInteger('context_window')->default(10)->after('llm_max_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn([
                'llm_model',
                'llm_fallback_model',
                'system_prompt',
                'llm_temperature',
                'llm_max_tokens',
                'context_window',
            ]);
        });
    }
};
