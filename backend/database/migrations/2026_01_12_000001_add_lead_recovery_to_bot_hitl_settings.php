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
        Schema::table('bot_hitl_settings', function (Blueprint $table) {
            // Note: lead_recovery_enabled is already in the create table migration
            // Only add the new columns if they don't exist
            if (!Schema::hasColumn('bot_hitl_settings', 'lead_recovery_timeout_hours')) {
                $table->integer('lead_recovery_timeout_hours')->default(4);
            }
            if (!Schema::hasColumn('bot_hitl_settings', 'lead_recovery_mode')) {
                $table->string('lead_recovery_mode', 10)->default('static');
            }
            if (!Schema::hasColumn('bot_hitl_settings', 'lead_recovery_message')) {
                $table->text('lead_recovery_message')->nullable();
            }
            if (!Schema::hasColumn('bot_hitl_settings', 'lead_recovery_max_attempts')) {
                $table->integer('lead_recovery_max_attempts')->default(2);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_hitl_settings', function (Blueprint $table) {
            // Drop only the new columns
            $columnsToDrop = [];
            if (Schema::hasColumn('bot_hitl_settings', 'lead_recovery_timeout_hours')) {
                $columnsToDrop[] = 'lead_recovery_timeout_hours';
            }
            if (Schema::hasColumn('bot_hitl_settings', 'lead_recovery_mode')) {
                $columnsToDrop[] = 'lead_recovery_mode';
            }
            if (Schema::hasColumn('bot_hitl_settings', 'lead_recovery_message')) {
                $columnsToDrop[] = 'lead_recovery_message';
            }
            if (Schema::hasColumn('bot_hitl_settings', 'lead_recovery_max_attempts')) {
                $columnsToDrop[] = 'lead_recovery_max_attempts';
            }
            
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
