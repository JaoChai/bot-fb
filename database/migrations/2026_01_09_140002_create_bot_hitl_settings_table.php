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
        Schema::create('bot_hitl_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_setting_id')->constrained('bot_settings')->onDelete('cascade');
            $table->boolean('hitl_enabled')->default(false);
            $table->json('hitl_triggers')->default('[]');
            $table->boolean('lead_recovery_enabled')->default(false);
            $table->boolean('reply_when_called_enabled')->default(false);
            $table->boolean('easy_slip_enabled')->default(false);
            $table->timestamps();

            $table->unique('bot_setting_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_hitl_settings');
    }
};
