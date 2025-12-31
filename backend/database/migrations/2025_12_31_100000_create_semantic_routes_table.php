<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Semantic routes table for fast intent classification
     * using vector similarity instead of LLM calls.
     */
    public function up(): void
    {
        Schema::create('semantic_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('intent', 50); // 'chat', 'knowledge', 'flow'
            $table->string('language', 10)->default('th'); // 'th', 'en'
            $table->text('example_phrase');
            $table->vector('embedding', 1536); // pgvector for text-embedding-3-small
            $table->float('weight')->default(1.0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Indexes for efficient queries
            $table->index(['bot_id', 'intent', 'is_active']);
            $table->index(['intent', 'language', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('semantic_routes');
    }
};
