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
        Schema::table('flows', function (Blueprint $table) {
            $table->boolean('second_ai_enabled')->default(false);
            $table->jsonb('second_ai_options')->default('{"fact_check": false, "policy": false, "personality": false}');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn(['second_ai_enabled', 'second_ai_options']);
        });
    }
};
