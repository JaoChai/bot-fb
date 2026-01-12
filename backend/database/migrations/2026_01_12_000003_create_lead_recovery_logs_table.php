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
        Schema::create('lead_recovery_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->integer('attempt_number');
            $table->string('message_mode', 10); // static, ai
            $table->text('message_sent');
            $table->timestamp('sent_at');
            $table->string('delivery_status', 20)->default('sent'); // sent, failed, blocked
            $table->text('error_message')->nullable();
            $table->boolean('customer_responded')->default(false);
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            // Index for analytics queries
            $table->index(['bot_id', 'sent_at']);

            // Index for response tracking
            $table->index(['conversation_id', 'customer_responded']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_recovery_logs');
    }
};
