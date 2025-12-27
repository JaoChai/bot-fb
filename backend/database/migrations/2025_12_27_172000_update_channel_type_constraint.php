<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        DB::statement('ALTER TABLE bots DROP CONSTRAINT IF EXISTS bots_channel_type_check');
        DB::statement("ALTER TABLE bots ADD CONSTRAINT bots_channel_type_check CHECK (channel_type IN ('line', 'facebook', 'telegram', 'testing', 'demo'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE bots DROP CONSTRAINT IF EXISTS bots_channel_type_check');
        DB::statement("ALTER TABLE bots ADD CONSTRAINT bots_channel_type_check CHECK (channel_type IN ('line', 'facebook', 'telegram'))");
    }
};
