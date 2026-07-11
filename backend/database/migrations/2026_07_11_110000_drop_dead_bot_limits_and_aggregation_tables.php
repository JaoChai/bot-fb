<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the dead bot_limits and bot_aggregation_settings tables.
 *
 * Both tables have zero runtime readers or writers — the live rate-limit and
 * aggregation features read/write the bot_settings table instead. These parallel
 * tables were created (2026_01_09) but never wired up, and hold 0 rows.
 *
 * down() recreates them from their original definitions so the rollback chain
 * (incl. 2026_02_16_100002 which re-adds their unique constraints) stays intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('bot_limits');
        Schema::dropIfExists('bot_aggregation_settings');
    }

    public function down(): void
    {
        Schema::create('bot_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_setting_id')->constrained('bot_settings')->onDelete('cascade');
            $table->integer('daily_message_limit')->nullable();
            $table->integer('per_user_limit')->nullable();
            $table->integer('rate_limit_per_minute')->default(30);
            $table->integer('max_tokens_per_response')->nullable();
            $table->text('rate_limit_bot_message')->nullable();
            $table->text('rate_limit_user_message')->nullable();
            $table->timestamps();

            $table->unique('bot_setting_id');
        });

        Schema::create('bot_aggregation_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_setting_id')->constrained('bot_settings')->onDelete('cascade');
            $table->boolean('multiple_bubbles_enabled')->default(true);
            $table->integer('multiple_bubbles_min')->default(3);
            $table->integer('multiple_bubbles_max')->default(5);
            $table->string('multiple_bubbles_delimiter')->default('\n\n---\n\n');
            $table->boolean('wait_multiple_bubbles_enabled')->default(false);
            $table->integer('wait_multiple_bubbles_ms')->default(3000);
            $table->boolean('smart_aggregation_enabled')->default(true);
            $table->integer('smart_min_wait_ms')->default(1000);
            $table->integer('smart_max_wait_ms')->default(5000);
            $table->boolean('smart_early_trigger_enabled')->default(true);
            $table->boolean('smart_per_user_learning_enabled')->default(true);
            $table->timestamps();

            $table->unique('bot_setting_id');
        });
    }
};
