<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();

            // Usage limits
            $table->unsignedInteger('daily_message_limit')->default(1000);
            $table->unsignedInteger('per_user_limit')->default(100); // Messages per user per day
            $table->unsignedInteger('rate_limit_per_minute')->default(20);

            // Human-in-the-loop settings
            $table->boolean('hitl_enabled')->default(false);
            $table->json('hitl_triggers')->nullable(); // Keywords/conditions to trigger HITL

            // Response hours (when bot is active)
            $table->boolean('response_hours_enabled')->default(false);
            $table->json('response_hours')->nullable(); // {"mon": {"start": "09:00", "end": "18:00"}, ...}
            $table->text('offline_message')->nullable(); // Message when outside response hours

            // Auto-responses
            $table->text('welcome_message')->nullable();
            $table->text('fallback_message')->nullable(); // When AI can't respond
            $table->boolean('typing_indicator')->default(true);
            $table->unsignedInteger('typing_delay_ms')->default(1000); // Delay before responding

            // Content moderation
            $table->boolean('content_filter_enabled')->default(true);
            $table->json('blocked_keywords')->nullable();

            // Analytics
            $table->boolean('analytics_enabled')->default(true);
            $table->boolean('save_conversations')->default(true);

            $table->timestamps();

            $table->unique('bot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_settings');
    }
};
