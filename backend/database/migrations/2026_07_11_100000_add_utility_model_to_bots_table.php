<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            // โมเดลสำหรับงานเบื้องหลังจิ๋ว (entity extraction, plugin trigger, lead recovery)
            // null = ใช้ fallback_chat_model → primary_chat_model
            $table->string('utility_model', 100)->nullable()->after('fallback_chat_model');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('utility_model');
        });
    }
};
