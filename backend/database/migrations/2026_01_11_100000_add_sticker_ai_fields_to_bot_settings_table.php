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
            $table->string('reply_sticker_mode', 20)->default('static')->after('reply_sticker_message');
            $table->text('reply_sticker_ai_prompt')->nullable()->after('reply_sticker_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn(['reply_sticker_mode', 'reply_sticker_ai_prompt']);
        });
    }
};
