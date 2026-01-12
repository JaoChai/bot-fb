<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Drop second_ai_model column - now using Bot's decision_model instead.
     */
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn('second_ai_model');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->string('second_ai_model', 255)
                ->nullable()
                ->default('openai/gpt-4o-mini')
                ->after('second_ai_enabled');
        });
    }
};
