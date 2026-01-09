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
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->boolean('reply_sticker_enabled')->default(false)->after('wait_multiple_bubbles_ms');
            $table->string('reply_sticker_message', 500)->nullable()->after('reply_sticker_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn(['reply_sticker_enabled', 'reply_sticker_message']);
        });
    }
};
