<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wave 2 #4b: make pending aggregation authoritative in the database so a
     * Redis drop cannot lose waiting customer messages, and back the response
     * lease with a DB row so the fence outlives any cache outage.
     *
     * Additive only: the old release keeps reading/writing its cache groups
     * while these tables fill up; no existing table or column is touched.
     */
    public function up(): void
    {
        Schema::create('message_aggregation_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                ->unique()
                ->constrained('conversations')
                ->cascadeOnDelete();
            $table->uuid('group_id');
            $table->json('message_ids');
            $table->timestamp('started_at')->nullable();
            // Advisory only: when the debounce expected to fire. Never used to
            // expire unsent work.
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
        });

        Schema::create('conversation_response_leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                ->unique()
                ->constrained('conversations')
                ->cascadeOnDelete();
            $table->string('owner_token', 64);
            $table->timestamp('lease_until');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_response_leases');
        Schema::dropIfExists('message_aggregation_groups');
    }
};
