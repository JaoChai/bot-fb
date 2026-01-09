<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            // Smart aggregation settings - after wait_multiple_bubbles_ms
            $table->boolean('smart_aggregation_enabled')
                ->default(false)
                ->after('wait_multiple_bubbles_ms');

            $table->unsignedSmallInteger('smart_min_wait_ms')
                ->default(500)
                ->after('smart_aggregation_enabled');

            $table->unsignedSmallInteger('smart_max_wait_ms')
                ->default(3000)
                ->after('smart_min_wait_ms');

            $table->boolean('smart_early_trigger_enabled')
                ->default(true)
                ->after('smart_max_wait_ms');

            // Phase 4: Per-user learning
            $table->boolean('smart_per_user_learning_enabled')
                ->default(false)
                ->after('smart_early_trigger_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'smart_aggregation_enabled',
                'smart_min_wait_ms',
                'smart_max_wait_ms',
                'smart_early_trigger_enabled',
                'smart_per_user_learning_enabled',
            ]);
        });
    }
};
