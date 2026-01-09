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
        Schema::create('evaluation_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();

            // Report content
            $table->text('executive_summary');
            $table->json('strengths')->nullable();
            $table->json('weaknesses')->nullable();
            $table->json('recommendations')->nullable();
            $table->json('prompt_suggestions')->nullable(); // Suggested system prompt improvements
            $table->json('kb_gaps')->nullable(); // Missing KB content areas

            // Comparison data
            $table->json('historical_comparison')->nullable(); // Comparison with previous evals

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_reports');
    }
};
