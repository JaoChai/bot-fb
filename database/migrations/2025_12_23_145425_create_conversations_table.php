<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_profile_id')->nullable()->constrained()->nullOnDelete();

            // External identifiers
            $table->string('external_customer_id'); // LINE User ID or Facebook PSID
            $table->enum('channel_type', ['line', 'facebook', 'demo']);

            // Conversation state
            $table->enum('status', ['active', 'closed', 'handover'])->default('active');
            $table->boolean('is_handover')->default(false); // Human-in-the-loop active
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Context memory (for AI)
            $table->json('memory_notes')->nullable(); // AI-generated conversation memory
            $table->json('tags')->nullable();
            $table->json('context')->nullable(); // Current conversation context

            // Flow tracking
            $table->foreignId('current_flow_id')->nullable()->constrained('flows')->nullOnDelete();

            // Stats
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('bot_id');
            $table->index('external_customer_id');
            $table->index('status');
            $table->index(['bot_id', 'status']);
            $table->index(['bot_id', 'external_customer_id']);
            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
