<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();

            // AI Configuration
            $table->text('system_prompt');
            $table->string('model')->default('gpt-4o-mini');
            $table->decimal('temperature', 3, 2)->default(0.7);
            $table->integer('max_tokens')->default(1024);

            // Agentic mode settings
            $table->boolean('agentic_mode')->default(false);
            $table->integer('max_tool_calls')->default(5);
            $table->json('enabled_tools')->nullable(); // List of enabled tool names

            // Knowledge Base connection
            $table->foreignId('knowledge_base_id')->nullable()->constrained('knowledge_bases')->nullOnDelete();
            $table->integer('kb_top_k')->default(5); // Number of chunks to retrieve
            $table->decimal('kb_similarity_threshold', 4, 3)->default(0.7);

            // Response settings
            $table->string('language')->default('th');
            $table->boolean('is_default')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index('bot_id');
            $table->index(['bot_id', 'is_default']);
        });

        // Add foreign key constraint for default_flow_id in bots table
        Schema::table('bots', function (Blueprint $table) {
            $table->foreign('default_flow_id')->references('id')->on('flows')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropForeign(['default_flow_id']);
        });

        Schema::dropIfExists('flows');
    }
};
