<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Semantic Cache table for RAG responses.
     * Uses pgvector for semantic similarity search on cached queries.
     */
    public function up(): void
    {
        Schema::create('rag_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->onDelete('cascade');

            // Original query text (for display/debugging)
            $table->text('query_text');

            // Normalized query (lowercase, trimmed) for faster exact matching
            $table->string('query_normalized', 500)->index();

            // Query embedding vector for semantic similarity search
            // Uses pgvector extension (1536 dimensions for text-embedding-3-small)
            // Only add vector column on PostgreSQL, use text on SQLite for testing
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->vector('query_embedding', 1536)->nullable();
            } else {
                $table->text('query_embedding')->nullable();
            }

            // Cached response content
            $table->text('response');

            // Metadata: intent, rag info, model used, etc.
            $table->jsonb('metadata')->nullable();

            // Cache hit statistics
            $table->unsignedInteger('hit_count')->default(0);
            $table->timestamp('last_hit_at')->nullable();

            // TTL management
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at');

            // Indexes for efficient lookups
            $table->index(['bot_id', 'expires_at']);
            $table->index('expires_at'); // For cleanup job
        });

        // Create HNSW index for fast vector similarity search
        // This makes semantic search ~10x faster than brute-force
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX rag_cache_embedding_idx ON rag_cache USING hnsw (query_embedding vector_cosine_ops)');
        }
        // SQLite doesn't support pgvector HNSW indexes - skip for testing
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rag_cache');
    }
};
