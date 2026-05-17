<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-shot data migration: clear timers for in-flight handover conversations.
 *
 * The default toggle behavior changed from "30-min auto-enable" to "permanent
 * until manually re-enabled". Pre-existing rows still carry a timer and would
 * be silently re-enabled by AutoEnableBots, contradicting the new promise.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('conversations')
            ->where('is_handover', true)
            ->whereNotNull('bot_auto_enable_at')
            ->update(['bot_auto_enable_at' => null]);
    }

    public function down(): void
    {
        // No-op: timer values are not recoverable.
    }
};
