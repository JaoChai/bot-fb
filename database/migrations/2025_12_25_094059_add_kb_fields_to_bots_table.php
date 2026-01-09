<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add Knowledge Base (KB) fields to bots table for RAG integration.
     *
     * These fields control how each bot uses its associated knowledge base
     * for Retrieval Augmented Generation (RAG) responses.
     */
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            // Enable/disable KB usage for this bot
            $table->boolean('kb_enabled')->default(false)->after('context_window');

            // Minimum similarity score (0-1) for KB results to be included
            // Higher = more strict, lower = more lenient
            $table->decimal('kb_relevance_threshold', 3, 2)->default(0.70)->after('kb_enabled');

            // Maximum number of KB chunks to include in context
            $table->unsignedTinyInteger('kb_max_results')->default(3)->after('kb_relevance_threshold');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn([
                'kb_enabled',
                'kb_relevance_threshold',
                'kb_max_results',
            ]);
        });
    }
};
