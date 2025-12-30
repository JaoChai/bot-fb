<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Change json columns to jsonb for better performance and
     * compatibility with PostgreSQL jsonb_* functions.
     */
    public function up(): void
    {
        // conversations table
        DB::statement('ALTER TABLE conversations ALTER COLUMN tags TYPE jsonb USING tags::jsonb');
        DB::statement('ALTER TABLE conversations ALTER COLUMN memory_notes TYPE jsonb USING memory_notes::jsonb');
        DB::statement('ALTER TABLE conversations ALTER COLUMN context TYPE jsonb USING context::jsonb');

        // customer_profiles table
        DB::statement('ALTER TABLE customer_profiles ALTER COLUMN tags TYPE jsonb USING tags::jsonb');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // conversations table
        DB::statement('ALTER TABLE conversations ALTER COLUMN tags TYPE json USING tags::json');
        DB::statement('ALTER TABLE conversations ALTER COLUMN memory_notes TYPE json USING memory_notes::json');
        DB::statement('ALTER TABLE conversations ALTER COLUMN context TYPE json USING context::json');

        // customer_profiles table
        DB::statement('ALTER TABLE customer_profiles ALTER COLUMN tags TYPE json USING tags::json');
    }
};
