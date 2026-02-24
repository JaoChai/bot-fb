<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Drop dead model fields from flows table.
     * Model selection uses Bot-level settings (primary_chat_model, fallback_chat_model, etc.)
     * These Flow-level model fields were never read by any backend service.
     */
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn([
                'model',
                'fallback_model',
                'decision_model',
                'fallback_decision_model',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->string('model')->default('gpt-4o-mini')->after('system_prompt');
            $table->string('fallback_model')->nullable()->after('model');
            $table->string('decision_model')->nullable()->after('fallback_model');
            $table->string('fallback_decision_model')->nullable()->after('decision_model');
        });
    }
};
