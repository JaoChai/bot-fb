<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add metadata column to messages table.
 *
 * This column stores RAG metadata (chunks used, similarity scores, etc.)
 * and other message-specific metadata as JSON.
 *
 * Fixes PHP-LARAVEL-1G: ProcessAggregatedMessages job failing due to
 * missing metadata column when saving bot responses.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('intents');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
