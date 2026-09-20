<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * OPENROUTER_API_KEY from the environment has been the only key source since #274.
     */
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $existingColumns = [];
            foreach (['openrouter_api_key', 'openrouter_model'] as $column) {
                if (Schema::hasColumn('user_settings', $column)) {
                    $existingColumns[] = $column;
                }
            }

            if (! empty($existingColumns)) {
                $table->dropColumn($existingColumns);
            }
        });
    }

    /**
     * Reverse the migrations.
     * Restores the schema only — the dropped key values cannot be restored.
     */
    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('user_settings', 'openrouter_api_key')) {
                $table->text('openrouter_api_key')->nullable();
            }

            if (! Schema::hasColumn('user_settings', 'openrouter_model')) {
                $table->string('openrouter_model')->default('openai/gpt-4o-mini');
            }
        });
    }
};
