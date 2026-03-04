<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the old check constraint and add new one with 'testing' option
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE bots DROP CONSTRAINT IF EXISTS bots_channel_type_check');
            DB::statement("ALTER TABLE bots ADD CONSTRAINT bots_channel_type_check CHECK (channel_type IN ('line', 'facebook', 'telegram', 'testing', 'demo'))");
        } elseif ($driver === 'sqlite') {
            // SQLite doesn't support ALTER CONSTRAINT, need to recreate table
            // For testing purposes, skip constraint update in SQLite
            // Tests will use the initial schema from create_bots_table migration
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE bots DROP CONSTRAINT IF EXISTS bots_channel_type_check');
            DB::statement("ALTER TABLE bots ADD CONSTRAINT bots_channel_type_check CHECK (channel_type IN ('line', 'facebook', 'telegram'))");
        } elseif ($driver === 'sqlite') {
            // SQLite doesn't support ALTER CONSTRAINT
            // For testing purposes, skip constraint rollback in SQLite
        }
    }
};
