<?php
// backend/database/migrations/2026_07_17_000000_add_reasoning_effort_to_bots.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            // nullable: validation อนุญาต null ได้ → กัน NOT NULL violation (500); RAGService `?: 'medium'` รับ null อยู่แล้ว
            $table->string('reasoning_effort', 10)->nullable()->default('medium')->after('utility_model');
        });
    }

    public function down(): void
    {
        Schema::table('bots', function (Blueprint $table) {
            $table->dropColumn('reasoning_effort');
        });
    }
};
