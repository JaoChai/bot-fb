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
        Schema::create('bot_response_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_setting_id')->constrained('bot_settings')->onDelete('cascade');
            $table->boolean('response_hours_enabled')->default(false);
            $table->json('response_hours')->nullable();
            $table->string('response_hours_timezone')->default('Asia/Bangkok');
            $table->text('offline_message')->nullable();
            $table->boolean('reply_sticker_enabled')->default(false);
            $table->text('reply_sticker_message')->nullable();
            $table->timestamps();

            $table->unique('bot_setting_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_response_hours');
    }
};
