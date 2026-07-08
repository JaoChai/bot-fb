<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->boolean('slip_verification_enabled')->default(false);
            $table->string('slip_receiver_account')->nullable();
            $table->decimal('slip_amount_tolerance', 8, 2)->default(0);
            $table->text('slip_success_message')->nullable();
            $table->text('slip_fail_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'slip_verification_enabled',
                'slip_receiver_account',
                'slip_amount_tolerance',
                'slip_success_message',
                'slip_fail_message',
            ]);
        });
    }
};
