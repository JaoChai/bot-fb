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
            // Telegram-specific fields
            $table->string('telegram_chat_type')->nullable()->after('channel_type');
            $table->string('telegram_chat_title')->nullable()->after('telegram_chat_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_type', 'telegram_chat_title']);
        });
    }
};
