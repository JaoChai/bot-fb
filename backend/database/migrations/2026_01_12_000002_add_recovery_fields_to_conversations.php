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
        Schema::table('conversations', function (Blueprint $table) {
            // Count of recovery attempts sent to this conversation
            $table->integer('recovery_attempts')->default(0)->after('bot_auto_enable_at');

            // Timestamp of the last recovery follow-up sent
            $table->timestamp('last_recovery_at')->nullable()->after('recovery_attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['recovery_attempts', 'last_recovery_at']);
        });
    }
};
