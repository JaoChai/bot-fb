<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_weekly_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->date('week_end');
            $table->string('status', 20)->default('generating');

            // Report data (JSONB)
            $table->jsonb('performance_summary')->default('{}');
            $table->jsonb('top_issues')->default('[]');
            $table->jsonb('prompt_suggestions')->default('[]');

            // Summary metrics
            $table->integer('total_conversations')->default(0);
            $table->integer('total_flagged')->default(0);
            $table->decimal('average_score', 5, 2)->default(0);
            $table->decimal('previous_average_score', 5, 2)->nullable();
            $table->decimal('generation_cost', 8, 4)->nullable();

            $table->timestamp('generated_at')->nullable();
            $table->boolean('notification_sent')->default(false);
            $table->timestamps();

            // Unique constraint
            $table->unique(['bot_id', 'week_start'], 'idx_qa_report_bot_week');
            $table->index('status', 'idx_qa_report_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_weekly_reports');
    }
};
