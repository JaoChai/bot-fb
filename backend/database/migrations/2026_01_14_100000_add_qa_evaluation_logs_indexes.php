<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qa_evaluation_logs', function (Blueprint $table) {
            // Index for filtering by issue_type
            $table->index('issue_type', 'idx_qa_eval_issue_type');

            // Index for cleanup command (WHERE created_at < ?)
            $table->index('created_at', 'idx_qa_eval_created_single');

            // Composite index for score range filtering
            $table->index(['bot_id', 'overall_score'], 'idx_qa_eval_bot_score');
        });
    }

    public function down(): void
    {
        Schema::table('qa_evaluation_logs', function (Blueprint $table) {
            $table->dropIndex('idx_qa_eval_issue_type');
            $table->dropIndex('idx_qa_eval_created_single');
            $table->dropIndex('idx_qa_eval_bot_score');
        });
    }
};
