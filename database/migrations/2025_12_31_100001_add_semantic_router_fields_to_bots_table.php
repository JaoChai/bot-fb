<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add semantic router and confidence cascade configuration fields to bots.
     */
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            // Semantic Router config
            $table->boolean('use_semantic_router')->default(true)->after('kb_max_results');
            $table->float('semantic_router_threshold')->default(0.75)->after('use_semantic_router');
            $table->string('semantic_router_fallback', 20)->default('llm')->after('semantic_router_threshold');

            // Confidence Cascade config
            $table->boolean('use_confidence_cascade')->default(false)->after('semantic_router_fallback');
            $table->float('cascade_confidence_threshold')->default(0.7)->after('use_confidence_cascade');
            $table->string('cascade_cheap_model')->nullable()->after('cascade_confidence_threshold');
            $table->string('cascade_expensive_model')->nullable()->after('cascade_cheap_model');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn([
                'use_semantic_router',
                'semantic_router_threshold',
                'semantic_router_fallback',
                'use_confidence_cascade',
                'cascade_confidence_threshold',
                'cascade_cheap_model',
                'cascade_expensive_model',
            ]);
        });
    }
};
