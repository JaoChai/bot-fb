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
            $table->text('rate_limit_bot_message')->nullable()->after('fallback_message');
            $table->text('rate_limit_user_message')->nullable()->after('rate_limit_bot_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn(['rate_limit_bot_message', 'rate_limit_user_message']);
        });
    }
};
