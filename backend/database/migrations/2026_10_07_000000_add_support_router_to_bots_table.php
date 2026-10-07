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
        Schema::table('bots', function (Blueprint $table) {
            // Support Router (Luna Decisions): per-bot classifier settings.
            // mode: off = disabled, shadow = score but reply via LLM, on = handover on threshold
            $table->string('support_router_mode', 10)->default('off')->after('reasoning_effort');
            $table->string('support_router_model', 100)->nullable()->after('support_router_mode');
            $table->text('support_handover_message')->nullable()->after('support_router_model');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn(['support_router_mode', 'support_router_model', 'support_handover_message']);
        });
    }
};
