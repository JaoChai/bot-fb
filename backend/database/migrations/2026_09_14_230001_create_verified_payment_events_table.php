<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verified_payment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('bot_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversation_id')->constrained()->restrictOnDelete();
            $table->foreignId('slip_verification_id')->constrained()->restrictOnDelete();
            $table->foreignId('receipt_message_id')->constrained('messages')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16);
            $table->string('event_key');
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['bot_id', 'event_key']);
            $table->unique('receipt_message_id');
            $table->index(['bot_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verified_payment_events');
    }
};
