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
            // Multiple bubbles feature
            $table->boolean('multiple_bubbles_enabled')->default(false)->after('rate_limit_user_message');
            $table->unsignedTinyInteger('multiple_bubbles_min')->default(1)->after('multiple_bubbles_enabled');
            $table->unsignedTinyInteger('multiple_bubbles_max')->default(3)->after('multiple_bubbles_min');
            $table->string('multiple_bubbles_delimiter', 10)->default('|||')->after('multiple_bubbles_max');

            // Wait/delay between bubbles
            $table->boolean('wait_multiple_bubbles_enabled')->default(false)->after('multiple_bubbles_delimiter');
            $table->unsignedSmallInteger('wait_multiple_bubbles_ms')->default(1500)->after('wait_multiple_bubbles_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'multiple_bubbles_enabled',
                'multiple_bubbles_min',
                'multiple_bubbles_max',
                'multiple_bubbles_delimiter',
                'wait_multiple_bubbles_enabled',
                'wait_multiple_bubbles_ms',
            ]);
        });
    }
};
