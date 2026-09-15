<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('bot_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversation_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->enum('state', [
                'draft',
                'awaiting_confirm',
                'awaiting_support',
                'awaiting_terms',
                'payable',
                'paid',
                'paid_hold',
                'cancelled',
            ]);
            $table->json('items');
            $table->unsignedBigInteger('total_minor');
            $table->string('currency', 3)->default('THB');
            $table->string('fingerprint', 64);
            $table->json('requirements');
            $table->json('accepted');
            $table->foreignId('challenge_message_id')->nullable()->constrained('messages')->restrictOnDelete();
            $table->timestamp('presented_at')->nullable();
            $table->uuid('settled_event_id')->nullable()->unique();
            $table->timestamps();

            $table->foreign('settled_event_id')
                ->references('id')
                ->on('verified_payment_events')
                ->restrictOnDelete();
            $table->index(['bot_id', 'conversation_id', 'state']);
        });

        Schema::table('verified_payment_events', function (Blueprint $table) {
            $table->uuid('checkout_id')->nullable()->after('conversation_id');
            $table->foreign('checkout_id')
                ->references('id')
                ->on('checkout_sessions')
                ->restrictOnDelete();
            $table->index('checkout_id');
        });
    }

    public function down(): void
    {
        Schema::table('verified_payment_events', function (Blueprint $table) {
            $table->dropForeign(['checkout_id']);
            $table->dropIndex(['checkout_id']);
            $table->dropColumn('checkout_id');
        });

        Schema::dropIfExists('checkout_sessions');
    }
};
