<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verified_payment_events', function (Blueprint $table) {
            $table->string('disposition', 32)->nullable()->after('actor_id');
            $table->string('hold_reason', 64)->nullable()->after('disposition');
            $table->timestamp('held_at')->nullable()->after('hold_reason');
            $table->index(['disposition', 'held_at']);
        });
    }

    public function down(): void
    {
        Schema::table('verified_payment_events', function (Blueprint $table) {
            $table->dropIndex(['disposition', 'held_at']);
            $table->dropColumn(['disposition', 'hold_reason', 'held_at']);
        });
    }
};
