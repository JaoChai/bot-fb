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
            // OpenRouter API key per-bot
            $table->text('openrouter_api_key')->nullable()->after('channel_secret');

            // Multi-model LLM configuration
            $table->string('primary_chat_model')->nullable()->after('llm_fallback_model');
            $table->string('fallback_chat_model')->nullable()->after('primary_chat_model');
            $table->string('decision_model')->nullable()->after('fallback_chat_model');
            $table->string('fallback_decision_model')->nullable()->after('decision_model');

            // Webhook forwarder toggle
            $table->boolean('webhook_forwarder_enabled')->default(false)->after('webhook_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn([
                'openrouter_api_key',
                'primary_chat_model',
                'fallback_chat_model',
                'decision_model',
                'fallback_decision_model',
                'webhook_forwarder_enabled',
            ]);
        });
    }
};
