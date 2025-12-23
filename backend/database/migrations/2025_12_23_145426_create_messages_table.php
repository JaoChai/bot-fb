<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();

            // Message details
            $table->enum('sender', ['user', 'bot', 'agent']); // agent = human operator
            $table->text('content');
            $table->enum('type', ['text', 'image', 'file', 'sticker', 'location', 'audio', 'video', 'template', 'flex'])->default('text');

            // Media attachments
            $table->string('media_url')->nullable();
            $table->string('media_type')->nullable();
            $table->json('media_metadata')->nullable();

            // AI metadata
            $table->string('model_used')->nullable(); // e.g., 'gpt-4o-mini'
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->decimal('cost', 10, 6)->nullable(); // USD cost

            // External message IDs (for reply/reference)
            $table->string('external_message_id')->nullable();
            $table->string('reply_to_message_id')->nullable();

            // Vector embedding for semantic search (1536 dimensions for OpenAI embeddings)
            $table->vector('embedding', 1536)->nullable();

            // Sentiment/Analysis
            $table->string('sentiment')->nullable(); // positive, negative, neutral
            $table->json('intents')->nullable(); // Detected intents

            $table->timestamps();

            $table->index('conversation_id');
            $table->index('sender');
            $table->index('created_at');
            $table->index(['conversation_id', 'created_at']);
        });

        // Create HNSW index for fast vector similarity search
        // Using raw SQL because Laravel doesn't support HNSW indexes natively
        DB::statement('CREATE INDEX messages_embedding_idx ON messages USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_embedding_idx');
        Schema::dropIfExists('messages');
    }
};
