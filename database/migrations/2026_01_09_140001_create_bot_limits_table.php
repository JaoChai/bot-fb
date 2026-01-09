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
        Schema::create('bot_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_setting_id')->constrained('bot_settings')->onDelete('cascade');
            $table->integer('daily_message_limit')->nullable();
            $table->integer('per_user_limit')->nullable();
            $table->integer('rate_limit_per_minute')->default(30);
            $table->integer('max_tokens_per_response')->nullable();
            $table->text('rate_limit_bot_message')->nullable();
            $table->text('rate_limit_user_message')->nullable();
            $table->timestamps();

            $table->unique('bot_setting_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_limits');
    }
};
