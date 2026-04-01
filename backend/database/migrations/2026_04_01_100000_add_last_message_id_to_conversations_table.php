<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add last_message_id to conversations for O(1) last message lookup.
 *
 * Replaces the slow ofMany('id', 'max') subquery with a direct FK lookup.
 * Backfills existing rows from messages table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('last_message_id')->nullable()->after('last_message_at');
            $table->foreign('last_message_id')->references('id')->on('messages')->nullOnDelete();
            $table->index('last_message_id');
        });

        // Backfill existing conversations
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('
                UPDATE conversations c
                SET last_message_id = (
                    SELECT MAX(m.id) FROM messages m WHERE m.conversation_id = c.id
                )
            ');
        }
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['last_message_id']);
            $table->dropIndex(['last_message_id']);
            $table->dropColumn('last_message_id');
        });
    }
};
