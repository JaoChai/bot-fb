<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive', 'paused'])->default('inactive');
            $table->enum('channel_type', ['line', 'facebook', 'demo'])->default('demo');

            // Channel credentials (encrypted in application)
            $table->text('channel_access_token')->nullable();
            $table->text('channel_secret')->nullable();
            $table->string('webhook_url')->nullable();
            $table->string('page_id')->nullable(); // Facebook Page ID or LINE Channel ID

            // Default flow for this bot
            $table->foreignId('default_flow_id')->nullable();

            // Stats
            $table->unsignedBigInteger('total_conversations')->default(0);
            $table->unsignedBigInteger('total_messages')->default(0);
            $table->timestamp('last_active_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('status');
            $table->index('channel_type');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bots');
    }
};
