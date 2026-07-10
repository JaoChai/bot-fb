<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ยุบเหลือคู่โมเดลเดียว (primary_chat_model + fallback_chat_model):
     * ลบคอลัมน์ decision pair, Smart Routing cascade และ legacy llm_model/llm_fallback_model
     * Snapshot ค่าเดิมของบอท 26/28 อยู่ใน docs/superpowers/specs/2026-07-10-consolidate-llm-models-design.md
     */
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn([
                'decision_model',
                'fallback_decision_model',
                'llm_model',
                'llm_fallback_model',
                'use_confidence_cascade',
                'cascade_confidence_threshold',
                'cascade_cheap_model',
                'cascade_expensive_model',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            // Definitions mirror the original migrations (2025_12_24, 2025_12_27, 2025_12_31)
            $table->string('llm_model', 100)->default('anthropic/claude-3.5-sonnet')->after('default_flow_id');
            $table->string('llm_fallback_model', 100)->default('openai/gpt-4o-mini')->after('llm_model');
            $table->string('decision_model')->nullable()->after('fallback_chat_model');
            $table->string('fallback_decision_model')->nullable()->after('decision_model');
            $table->boolean('use_confidence_cascade')->default(false)->after('semantic_router_fallback');
            $table->float('cascade_confidence_threshold')->default(0.7)->after('use_confidence_cascade');
            $table->string('cascade_cheap_model')->nullable()->after('cascade_confidence_threshold');
            $table->string('cascade_expensive_model')->nullable()->after('cascade_cheap_model');
        });
    }
};
