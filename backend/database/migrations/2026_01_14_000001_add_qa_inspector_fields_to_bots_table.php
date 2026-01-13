<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->boolean('qa_inspector_enabled')->default(false);
            $table->string('qa_realtime_model', 100)->default('google/gemini-2.5-flash-preview');
            $table->string('qa_realtime_fallback_model', 100)->default('openai/gpt-4o-mini');
            $table->string('qa_analysis_model', 100)->default('anthropic/claude-sonnet-4');
            $table->string('qa_analysis_fallback_model', 100)->default('openai/gpt-4o');
            $table->string('qa_report_model', 100)->default('anthropic/claude-opus-4-5');
            $table->string('qa_report_fallback_model', 100)->default('anthropic/claude-sonnet-4');
            $table->decimal('qa_score_threshold', 3, 2)->default(0.70);
            $table->integer('qa_sampling_rate')->default(100);
            $table->string('qa_report_schedule', 50)->default('monday_00:00');
            $table->jsonb('qa_notifications')->default('{"email": true, "alert": true, "slack": false}');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn([
                'qa_inspector_enabled',
                'qa_realtime_model',
                'qa_realtime_fallback_model',
                'qa_analysis_model',
                'qa_analysis_fallback_model',
                'qa_report_model',
                'qa_report_fallback_model',
                'qa_score_threshold',
                'qa_sampling_rate',
                'qa_report_schedule',
                'qa_notifications',
            ]);
        });
    }
};
