<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds daily cost limit field to user settings.
     */
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            // Daily spending limit in USD
            $table->decimal('max_daily_cost', 8, 2)
                ->default(10.00)
                ->after('openrouter_model')
                ->comment('Max daily AI spending in USD');

            // Monthly spending limit (optional)
            $table->decimal('max_monthly_cost', 10, 2)
                ->nullable()
                ->after('max_daily_cost')
                ->comment('Max monthly AI spending in USD, null = unlimited');

            // Enable/disable cost alerts
            $table->boolean('cost_alert_enabled')
                ->default(true)
                ->after('max_monthly_cost');

            // Threshold percentage for alerts (e.g., 80 = alert at 80% of limit)
            $table->integer('cost_alert_threshold')
                ->default(80)
                ->after('cost_alert_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn([
                'max_daily_cost',
                'max_monthly_cost',
                'cost_alert_enabled',
                'cost_alert_threshold',
            ]);
        });
    }
};
