<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            // Add fallback and decision model fields for multi-model architecture
            $table->string('fallback_model')->nullable()->after('model');
            $table->string('decision_model')->nullable()->after('fallback_model');
            $table->string('fallback_decision_model')->nullable()->after('decision_model');
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn(['fallback_model', 'decision_model', 'fallback_decision_model']);
        });
    }
};
