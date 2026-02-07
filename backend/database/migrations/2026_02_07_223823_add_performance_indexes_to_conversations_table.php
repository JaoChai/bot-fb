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
        Schema::table('conversations', function (Blueprint $table) {
            // Compound index for dashboard sorting (bot_id + status + last_message_at)
            $table->index(['bot_id', 'status', 'last_message_at'], 'conversations_bot_status_last_msg_idx');
            // Compound index for soft delete queries
            $table->index(['bot_id', 'deleted_at', 'status'], 'conversations_bot_deleted_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_bot_status_last_msg_idx');
            $table->dropIndex('conversations_bot_deleted_status_idx');
        });
    }
};
