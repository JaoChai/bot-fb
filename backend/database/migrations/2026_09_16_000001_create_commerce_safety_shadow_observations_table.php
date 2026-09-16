<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive, bot-scoped, redacted audit trail of what the commerce-safety
        // pipeline would have decided while a bot is in `shadow` mode. Never holds
        // customer text, contact details, slip images, account-delivery payloads
        // or credentials — only fixed category/outcome/reason codes. Not linked to
        // a conversation or message on purpose, to keep aggregation the only use.
        Schema::create('commerce_safety_shadow_observations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bot_id');
            $table->string('category', 40);
            $table->string('outcome', 40);
            $table->string('reason', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['bot_id', 'category', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_safety_shadow_observations');
    }
};
