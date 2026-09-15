<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_deployments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('bot_id')->constrained()->restrictOnDelete();
            $table->foreignId('flow_id')->constrained()->restrictOnDelete();
            $table->string('version', 32);
            $table->string('artifact_path', 255);
            $table->char('manifest_sha256', 64);
            $table->longText('previous_prompt');
            $table->char('previous_md5', 32);
            $table->char('previous_sha256', 64);
            $table->char('candidate_sha256', 64);
            $table->string('serving_model', 100);
            $table->string('reasoning_effort', 10);
            $table->string('prepared_by', 255);
            $table->string('applied_by', 255)->nullable();
            $table->string('rolled_back_by', 255)->nullable();
            $table->enum('status', ['prepared', 'applied', 'rolled_back', 'failed']);
            $table->string('failure_stage', 32)->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('apply_cache_verified_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamp('rollback_cache_verified_at')->nullable();
            $table->timestamps();
            $table->unique(['flow_id', 'candidate_sha256']);
            $table->index(['bot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_deployments');
    }
};
