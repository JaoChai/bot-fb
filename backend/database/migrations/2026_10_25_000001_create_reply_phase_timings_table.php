<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 2 · plan #6 (timing half): one additive row per bot reply attempt.
 * IDs and phase durations only — NO message text or customer identifiers
 * beyond bot_id/conversation_id (privacy rule from the card).
 * Null column = phase not run. Old code keeps running: table is write-once,
 * only new code inserts into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reply_phase_timings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bot_id')->index();
            $table->unsignedBigInteger('conversation_id')->nullable()->index();
            $table->string('job_id')->nullable();
            $table->string('aggregation_group_id')->nullable();
            $table->unsignedInteger('aggregation_wait_ms')->nullable();
            $table->unsignedInteger('queue_wait_ms')->nullable();
            $table->unsignedInteger('router_ms')->nullable();
            $table->unsignedInteger('intent_ms')->nullable();
            $table->unsignedInteger('retrieval_ms')->nullable();
            $table->unsignedInteger('primary_ms')->nullable();
            $table->unsignedInteger('fallback_ms')->nullable();
            $table->unsignedInteger('vip_retry_ms')->nullable();
            $table->unsignedInteger('send_ms')->nullable();
            $table->unsignedInteger('plugin_ms')->nullable();
            $table->unsignedInteger('total_ms')->nullable();
            $table->string('outcome');
            $table->string('model')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reply_phase_timings');
    }
};
