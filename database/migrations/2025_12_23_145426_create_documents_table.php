<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained()->cascadeOnDelete();

            // Document info
            $table->string('filename');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size'); // bytes
            $table->string('storage_path'); // S3 or local path

            // Processing status
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();

            // Chunking info
            $table->unsignedInteger('chunk_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index('knowledge_base_id');
            $table->index('status');
        });

        // Document chunks with embeddings
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            // Chunk content
            $table->text('content');
            $table->unsignedInteger('chunk_index'); // Order within document
            $table->unsignedInteger('start_char')->nullable();
            $table->unsignedInteger('end_char')->nullable();

            // Vector embedding (1536 dimensions for OpenAI text-embedding-3-small)
            // Only add vector column on PostgreSQL, use text on SQLite for testing
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->vector('embedding', 1536)->nullable();
            } else {
                $table->text('embedding')->nullable();
            }

            // Metadata
            $table->json('metadata')->nullable(); // page number, section, etc.

            $table->timestamps();

            $table->index('document_id');
            $table->index(['document_id', 'chunk_index']);
        });

        // Create HNSW index for fast vector similarity search on chunks (PostgreSQL only)
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX document_chunks_embedding_idx ON document_chunks USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS document_chunks_embedding_idx');
        }
        Schema::dropIfExists('document_chunks');
        Schema::dropIfExists('documents');
    }
};
