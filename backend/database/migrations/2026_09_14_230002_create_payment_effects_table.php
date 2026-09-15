<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_effects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('verified_payment_events')->restrictOnDelete();
            $table->enum('kind', ['line_receipt', 'telegram_payment', 'reserve_stock']);
            $table->enum('state', ['pending', 'running', 'succeeded', 'uncertain', 'failed'])->default('pending');
            // Frozen identifier only; deletion or reconfiguration must fail closed at execution.
            $table->unsignedBigInteger('plugin_id')->nullable();
            $table->string('remote_id')->nullable();
            $table->uuid('retry_key')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('transport_started_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'kind']);
            $table->index(['state', 'next_attempt_at']);
            $table->index(['state', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_effects');
    }
};
