<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add partial unique index to prevent duplicate messages from LINE webhook retries.
     * LINE may send the same webhook multiple times if response is slow.
     * Uses partial index (WHERE external_message_id IS NOT NULL) to exclude bot messages.
     */
    public function up(): void
    {
        // Use raw SQL for partial unique index (PostgreSQL specific)
        // This only indexes rows where external_message_id is not null (user messages only)
        DB::statement('
            CREATE UNIQUE INDEX messages_dedup_idx
            ON messages (conversation_id, external_message_id)
            WHERE external_message_id IS NOT NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_dedup_idx');
    }
};
