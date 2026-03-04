<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add GIN index for PostgreSQL full-text search on document_chunks.content
     * Uses 'simple' configuration to support Thai + English without stemming issues.
     *
     * This enables hybrid search (vector + keyword) for better RAG retrieval.
     */
    public function up(): void
    {
        // Only run on PostgreSQL
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Create GIN index for full-text search
        // Using 'simple' config for multilingual support (Thai + English)
        DB::statement("
            CREATE INDEX IF NOT EXISTS document_chunks_content_fts
            ON document_chunks
            USING GIN (to_tsvector('simple', content))
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS document_chunks_content_fts');
    }
};
