<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * API Key is now consolidated in user_settings table.
     */
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('openrouter_api_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->text('openrouter_api_key')->nullable()->after('channel_secret');
        });
    }
};
