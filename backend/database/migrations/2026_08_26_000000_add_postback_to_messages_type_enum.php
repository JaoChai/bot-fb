<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add 'postback' to the messages.type enum constraint.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_type_check');
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_type_check CHECK (type IN ('text', 'image', 'file', 'sticker', 'location', 'audio', 'video', 'template', 'flex', 'postback'))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE messages MODIFY type ENUM('text', 'image', 'file', 'sticker', 'location', 'audio', 'video', 'template', 'flex', 'postback') NOT NULL DEFAULT 'text'");
        } elseif ($driver === 'sqlite') {
            // SQLite doesn't support ALTER CONSTRAINT - skip for testing
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_type_check');
            DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_type_check CHECK (type IN ('text', 'image', 'file', 'sticker', 'location', 'audio', 'video', 'template', 'flex'))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE messages MODIFY type ENUM('text', 'image', 'file', 'sticker', 'location', 'audio', 'video', 'template', 'flex') NOT NULL DEFAULT 'text'");
        } elseif ($driver === 'sqlite') {
            // SQLite doesn't support ALTER CONSTRAINT - skip for testing
        }
    }
};
