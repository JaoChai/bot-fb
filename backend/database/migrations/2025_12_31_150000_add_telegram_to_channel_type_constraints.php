<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add 'telegram' to channel_type constraints for conversations and customer_profiles tables.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Update conversations table constraint
            DB::statement('ALTER TABLE conversations DROP CONSTRAINT IF EXISTS conversations_channel_type_check');
            DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_channel_type_check CHECK (channel_type IN ('line', 'facebook', 'telegram', 'demo'))");

            // Update customer_profiles table constraint
            DB::statement('ALTER TABLE customer_profiles DROP CONSTRAINT IF EXISTS customer_profiles_channel_type_check');
            DB::statement("ALTER TABLE customer_profiles ADD CONSTRAINT customer_profiles_channel_type_check CHECK (channel_type IN ('line', 'facebook', 'telegram', 'demo'))");
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
            // Revert conversations table constraint
            DB::statement('ALTER TABLE conversations DROP CONSTRAINT IF EXISTS conversations_channel_type_check');
            DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_channel_type_check CHECK (channel_type IN ('line', 'facebook', 'demo'))");

            // Revert customer_profiles table constraint
            DB::statement('ALTER TABLE customer_profiles DROP CONSTRAINT IF EXISTS customer_profiles_channel_type_check');
            DB::statement("ALTER TABLE customer_profiles ADD CONSTRAINT customer_profiles_channel_type_check CHECK (channel_type IN ('line', 'facebook', 'demo'))");
        } elseif ($driver === 'sqlite') {
            // SQLite doesn't support ALTER CONSTRAINT - skip for testing
        }
    }
};
