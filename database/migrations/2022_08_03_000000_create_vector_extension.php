<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Only create pgvector extension on PostgreSQL
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Only drop pgvector extension on PostgreSQL
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP EXTENSION vector');
        }
    }
};
