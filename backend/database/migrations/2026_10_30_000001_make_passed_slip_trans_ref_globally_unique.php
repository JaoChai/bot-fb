<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * สลิปเดียวใช้ยืนยันได้ครั้งเดียวทั้งระบบ: trans_ref ที่เคย passed ห้าม passed ซ้ำ
     * ไม่ว่าจะบอทไหน (เดิม unique ต่อบอท — บอทคนละร้านยังรับสลิปซ้ำได้อยู่)
     * สร้าง index ใหม่ก่อน แล้วค่อยลบตัวเก่า: ถ้ามีข้อมูลซ้ำข้ามบอท จะพังตรงนี้ปลอดภัยกว่า
     */
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX slip_verifications_trans_ref_passed_unique
             ON slip_verifications (trans_ref) WHERE status = \'passed\''
        );
        DB::statement('DROP INDEX IF EXISTS slip_verifications_passed_trans_ref_unique');
    }

    public function down(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX slip_verifications_passed_trans_ref_unique
             ON slip_verifications (bot_id, trans_ref) WHERE status = \'passed\''
        );
        DB::statement('DROP INDEX IF EXISTS slip_verifications_trans_ref_passed_unique');
    }
};
