<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->index(); // LINE User ID or Facebook PSID
            $table->enum('channel_type', ['line', 'facebook', 'demo']);

            // Profile info (from channel API or manually set)
            $table->string('display_name')->nullable();
            $table->string('picture_url')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // Engagement stats
            $table->unsignedInteger('interaction_count')->default(0);
            $table->timestamp('first_interaction_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable();

            // Flexible metadata for custom fields
            $table->json('metadata')->nullable();
            $table->json('tags')->nullable();

            // Notes for HITL agents
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['external_id', 'channel_type']);
            $table->index('channel_type');
            $table->index('last_interaction_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
