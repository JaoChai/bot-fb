<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slip_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained()->onDelete('cascade');
            $table->foreignId('conversation_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('message_id')->nullable()->constrained()->onDelete('set null');
            $table->string('trans_ref')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('receiver_account')->nullable();
            // passed|fake|duplicate|amount_mismatch|wrong_account|no_pending_order|api_error
            $table->string('status', 32);
            $table->jsonb('raw_response')->nullable();
            $table->timestamps();

            $table->index(['bot_id', 'created_at']);
        });

        // กันสลิปซ้ำ: trans_ref เดิมห้ามมีสถานะ passed ซ้ำใน bot เดียวกัน
        DB::statement(
            'CREATE UNIQUE INDEX slip_verifications_passed_trans_ref_unique
             ON slip_verifications (bot_id, trans_ref) WHERE status = \'passed\''
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('slip_verifications');
    }
};
