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
            // Language and style
            $table->string('language', 10)->default('th')->after('save_conversations');
            $table->string('response_style', 20)->default('professional')->after('language');

            // Conversation management
            $table->unsignedInteger('auto_archive_days')->nullable()->after('response_style');

            // Additional rate limit
            $table->unsignedInteger('max_tokens_per_response')->default(2000)->after('rate_limit_per_minute');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'language',
                'response_style',
                'auto_archive_days',
                'max_tokens_per_response',
            ]);
        });
    }
};
