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
        Schema::create('bot_aggregation_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_setting_id')->constrained('bot_settings')->onDelete('cascade');
            $table->boolean('multiple_bubbles_enabled')->default(true);
            $table->integer('multiple_bubbles_min')->default(3);
            $table->integer('multiple_bubbles_max')->default(5);
            $table->string('multiple_bubbles_delimiter')->default('\n\n---\n\n');
            $table->boolean('wait_multiple_bubbles_enabled')->default(false);
            $table->integer('wait_multiple_bubbles_ms')->default(3000);
            $table->boolean('smart_aggregation_enabled')->default(true);
            $table->integer('smart_min_wait_ms')->default(1000);
            $table->integer('smart_max_wait_ms')->default(5000);
            $table->boolean('smart_early_trigger_enabled')->default(true);
            $table->boolean('smart_per_user_learning_enabled')->default(true);
            $table->timestamps();

            $table->unique('bot_setting_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_aggregation_settings');
    }
};
