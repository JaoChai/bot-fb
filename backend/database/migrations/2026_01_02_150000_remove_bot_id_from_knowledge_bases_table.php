<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Decouples Knowledge Bases from Bots to allow:
     * - 1 KB can be used by multiple Bots (via Flow relationship)
     * - Deleting a Bot won't delete its KB
     * - KBs become standalone resources owned by Users
     */
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table) {
            // Drop foreign key constraint first
            $table->dropForeign(['bot_id']);
            
            // Drop the index
            $table->dropIndex(['bot_id']);
            
            // Drop the column
            $table->dropColumn('bot_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table) {
            $table->foreignId('bot_id')
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();
                
            $table->index('bot_id');
        });
    }
};
