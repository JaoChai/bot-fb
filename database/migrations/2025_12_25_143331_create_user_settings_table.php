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
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // OpenRouter Settings
            $table->text('openrouter_api_key')->nullable(); // Encrypted
            $table->string('openrouter_model')->default('openai/gpt-4o-mini');

            // LINE Channel Settings
            $table->text('line_channel_secret')->nullable(); // Encrypted
            $table->text('line_channel_access_token')->nullable(); // Encrypted

            $table->timestamps();

            $table->unique('user_id'); // One settings record per user
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
