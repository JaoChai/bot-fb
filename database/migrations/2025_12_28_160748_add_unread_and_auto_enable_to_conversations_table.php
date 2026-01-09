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
            // Unread message count for admin notification
            $table->unsignedInteger('unread_count')->default(0)->after('message_count');

            // Timestamp when bot should auto-enable (null = no timer active)
            $table->timestamp('bot_auto_enable_at')->nullable()->after('is_handover');

            // Index for queries filtering unread conversations
            $table->index('unread_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['unread_count']);
            $table->dropColumn(['unread_count', 'bot_auto_enable_at']);
        });
    }
};
