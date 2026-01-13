<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_evaluation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_id')->nullable()->constrained()->nullOnDelete();

            // 5 evaluation metrics
            $table->decimal('answer_relevancy', 3, 2)->nullable();
            $table->decimal('faithfulness', 3, 2)->nullable();
            $table->decimal('role_adherence', 3, 2)->nullable();
            $table->decimal('context_precision', 3, 2)->nullable();
            $table->decimal('task_completion', 3, 2)->nullable();
            $table->decimal('overall_score', 3, 2);

            // Issue tracking
            $table->boolean('is_flagged')->default(false);
            $table->string('issue_type', 50)->nullable();
            $table->jsonb('issue_details')->nullable();

            // Conversation data
            $table->text('user_question');
            $table->text('bot_response');
            $table->text('system_prompt_used')->nullable();
            $table->jsonb('kb_chunks_used')->nullable();
            $table->jsonb('model_metadata')->nullable();

            $table->timestamp('evaluated_at');
            $table->timestamps();

            // Indexes
            $table->index('bot_id', 'idx_qa_eval_bot_id');
            $table->index('conversation_id', 'idx_qa_eval_conversation_id');
            $table->index(['bot_id', 'is_flagged', 'created_at'], 'idx_qa_eval_flagged');
            $table->index(['bot_id', 'created_at'], 'idx_qa_eval_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_evaluation_logs');
    }
};
