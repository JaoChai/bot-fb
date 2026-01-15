<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add LINE event tracking columns to messages table.
     *
     * LINE Messaging API Best Practices:
     * - webhook_event_id: LINE's unique event identifier for better deduplication
     * - is_redelivery: Flag indicating if webhook was redelivered
     * - event_timestamp: Event timestamp in milliseconds for ordering
     *
     * @see https://developers.line.biz/en/docs/messaging-api/receiving-messages/
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // LINE's unique webhook event ID (more reliable than message.id for deduplication)
            $table->string('webhook_event_id')->nullable()->after('external_message_id');

            // Flag indicating if this was a redelivered webhook from LINE
            $table->boolean('is_redelivery')->default(false)->after('webhook_event_id');

            // LINE event timestamp in milliseconds since epoch
            $table->bigInteger('event_timestamp')->nullable()->after('is_redelivery');
        });

        // Create partial unique index on webhook_event_id for deduplication
        // Only indexes non-null values (excludes bot messages which don't have webhook_event_id)
        DB::statement('
            CREATE UNIQUE INDEX messages_webhook_event_id_idx
            ON messages (conversation_id, webhook_event_id)
            WHERE webhook_event_id IS NOT NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the partial unique index first
        DB::statement('DROP INDEX IF EXISTS messages_webhook_event_id_idx');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['webhook_event_id', 'is_redelivery', 'event_timestamp']);
        });
    }
};
