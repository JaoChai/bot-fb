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
        // Drop tables with foreign key dependencies first
        Schema::dropIfExists('evaluation_messages');
        Schema::dropIfExists('evaluation_reports');
        Schema::dropIfExists('evaluation_test_cases');
        Schema::dropIfExists('improvement_suggestions');
        Schema::dropIfExists('improvement_sessions');
        Schema::dropIfExists('evaluations');
        Schema::dropIfExists('qa_evaluation_logs');
        Schema::dropIfExists('qa_weekly_reports');

        // Remove QA inspector columns from bots table
        if (Schema::hasColumn('bots', 'qa_inspector_enabled')) {
            Schema::table('bots', function (Blueprint $table) {
                $columns = [
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
                ];

                $existingColumns = [];
                foreach ($columns as $column) {
                    if (Schema::hasColumn('bots', $column)) {
                        $existingColumns[] = $column;
                    }
                }

                if (!empty($existingColumns)) {
                    $table->dropColumn($existingColumns);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not reversible - tables and data are gone
    }
};
