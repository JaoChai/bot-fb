<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('improvement_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')
                ->constrained('improvement_sessions')->cascadeOnDelete();

            // Type
            $table->string('type'); // system_prompt, kb_content
            $table->string('priority')->default('medium'); // high, medium, low
            $table->float('confidence_score')->nullable();

            // Content
            $table->string('title');
            $table->text('description')->nullable();

            // For system_prompt
            $table->text('current_value')->nullable();
            $table->text('suggested_value')->nullable();
            $table->text('diff_summary')->nullable();

            // For kb_content
            $table->foreignId('target_knowledge_base_id')->nullable()
                ->constrained('knowledge_bases')->nullOnDelete();
            $table->string('kb_content_title')->nullable();
            $table->text('kb_content_body')->nullable();
            $table->json('related_topics')->nullable();

            // Tracking
            $table->boolean('is_selected')->default(true);
            $table->boolean('is_applied')->default(false);
            $table->timestamp('applied_at')->nullable();

            // Source
            $table->string('source_metric', 50)->nullable();
            $table->json('source_test_case_ids')->nullable();

            $table->timestamps();

            $table->index('type');
            $table->index('session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('improvement_suggestions');
    }
};
