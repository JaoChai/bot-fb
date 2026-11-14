<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ขยาย lead_recovery_logs ให้รองรับ "ตามลูกค้า" (plan §3.1)
     * - คง id, conversation_id, bot_id, attempt_number, message_mode, delivery_status,
     *   error_message, customer_responded, responded_at, timestamps เดิมไว้ทั้งหมด
     * - message_sent / sent_at เป็น nullable — candidate ไม่ใช่การส่งจริง
     * - สร้าง follow_up_approvals + follow_up_sales_signals และ reverse ลูกก่อนคอลัมน์
     */
    public function up(): void
    {
        $this->guardLockTime();

        Schema::table('lead_recovery_logs', function (Blueprint $table) {
            // candidate ไม่ใช่การส่ง — payload เดิมกลายเป็น nullable
            $table->text('message_sent')->nullable()->change();
            $table->timestamp('sent_at')->nullable()->change();

            // anchor เงียบ + ภาพข้อความสุดท้ายตอนพบ
            $table->foreignId('silence_user_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('observed_last_message_id')->nullable()->constrained('messages')->nullOnDelete();
            // SHA256 ของ bot_id|line|external_customer_id — ไม่ข้ามบอท/ช่องทาง, แถวเดิม null
            $table->char('customer_key', 64)->nullable();

            // สถานะการขาย ณ ตอนพบ
            $table->timestamp('sales_started_at')->nullable();
            $table->boolean('payment_step')->default(false);
            $table->boolean('transfer_summary_sent')->default(false);

            // ผลตัดสินแบบ deterministic (G1-G4 อยู่ที่ชั้นแอปพลิเคชัน)
            $table->string('group_code', 2)->nullable();
            $table->decimal('group_confidence', 5, 4)->nullable();
            $table->decimal('declined_score', 5, 4)->nullable();
            $table->string('decision_version', 32)->nullable();

            // บริบทเรดะค์ (ไม่มีข้อความดิบ/ลิงก์/ชื่อ) และคำตอบของ Luna ย่อย
            $table->json('context_redacted')->nullable();
            $table->json('sort_answers')->nullable();
            $table->json('check_answers')->nullable();

            // จองโควตาร่างข้อความรายวัน
            $table->text('draft')->nullable();
            $table->timestamp('drafted_at')->nullable();
            $table->date('draft_day')->nullable();
            $table->timestamp('draft_reserved_at')->nullable();
            $table->string('draft_model', 191)->nullable();

            // การกระทำของเจ้าของ (audit)
            $table->string('owner_action', 16)->nullable();
            $table->string('owner_telegram_user_id', 32)->nullable();
            $table->timestamp('owner_action_at')->nullable();
            $table->boolean('check_overridden')->default(false);

            // ส่งจริง / ซ้ำ
            $table->uuid('retry_key')->nullable();
            $table->timestamp('first_push_at')->nullable();
            $table->string('line_request_id', 191)->nullable();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();

            // งาน worker อ้างสิทธิ์
            $table->timestamp('next_attempt_at')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->uuid('claim_token')->nullable();
            // เก็บรหัสเหตุผลเท่านั้น ห้ามเก็บ payload/ข้อความ exception
            $table->string('reason_code', 64)->nullable();

            // ความหน่วงและค่าใช้จ่ายโมเดล (ไม่มี usage → null ไม่ใช่ 0)
            $table->integer('sort_latency_ms')->nullable();
            $table->integer('draft_latency_ms')->nullable();
            $table->integer('check_latency_ms')->nullable();
            $table->decimal('model_cost', 12, 6)->nullable();
            $table->json('usage_totals')->nullable();

            // หน้าต่างผลลัพธ์: ตอบกลับ 72 ชม. / สั่งซื้อ 7 วัน
            $table->boolean('replied_within_72h')->nullable();
            $table->timestamp('response_window_closed_at')->nullable();
            $table->boolean('ordered_within_7d')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('order_window_closed_at')->nullable();

            // กุญแจและดัชนีตามแผน §3.1
            $table->unique(['conversation_id', 'silence_user_message_id']);
            $table->unique('retry_key');
            $table->unique('message_id');
            $table->index(['customer_key', 'sent_at']);
            $table->index(['bot_id', 'draft_day']);
            $table->index(['delivery_status', 'next_attempt_at']);
            $table->index(['bot_id', 'created_at', 'id']);
        });

        Schema::create('follow_up_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_recovery_log_id')->constrained('lead_recovery_logs')->cascadeOnDelete();
            // ค่าที่อนุญาตเป็นค่าคงที่บน FollowUpApproval (STATUS_*)
            $table->string('status', 16)->default('pending');
            $table->integer('revision')->default(1);
            // random 8 bytes hex
            $table->char('nonce', 16);
            // ฉบับร่าง/แก้ไขปัจจุบันที่ sanitize แล้ว — ฉบับแรก/ตัวตรวจดั้งเดิมอยู่บน log
            $table->text('text');
            $table->json('check_answers')->nullable();
            $table->boolean('check_overridden')->default(false);
            $table->timestamp('expires_at');
            $table->foreignId('telegram_plugin_id')->nullable()->constrained('flow_plugins')->nullOnDelete();
            $table->string('telegram_chat_id', 64)->nullable();
            // id ฝั่ง Telegram ล้วนๆ ไม่ผูก FK กับตาราง messages ในระบบ
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            // ค่าที่อนุญาตเป็นค่าคงที่บน FollowUpApproval (TELEGRAM_STATE_*)
            $table->string('telegram_delivery_state', 16)->default('new');
            $table->unsignedBigInteger('edit_prompt_message_id')->nullable();
            $table->string('editor_telegram_user_id', 32)->nullable();
            $table->unsignedBigInteger('last_update_id')->nullable();
            $table->integer('confirmed_revision')->nullable();
            $table->timestamps();

            $table->unique('lead_recovery_log_id');
            $table->index(['status', 'expires_at']);
        });

        Schema::create('follow_up_sales_signals', function (Blueprint $table) {
            // read model ต่อห้อง ไม่เก็บข้อความดิบ
            $table->unsignedBigInteger('conversation_id')->primary();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreignId('last_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('last_user_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('last_user_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->foreignId('latest_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('sales_started_at');
            $table->integer('customer_message_count')->default(0);
            $table->boolean('payment_step')->default(false);
            $table->boolean('transfer_summary_sent')->default(false);
            $table->boolean('sensitive_account_data')->default(false);
            $table->timestamp('projected_at');
            $table->integer('projection_version')->default(1);
        });
    }

    public function down(): void
    {
        $this->guardLockTime();

        // ลูกก่อนคอลัมน์/อ้างอิงที่เพิ่ม
        Schema::dropIfExists('follow_up_approvals');
        Schema::dropIfExists('follow_up_sales_signals');

        Schema::table('lead_recovery_logs', function (Blueprint $table) {
            $table->dropUnique(['conversation_id', 'silence_user_message_id']);
            $table->dropUnique(['retry_key']);
            $table->dropUnique(['message_id']);
            $table->dropIndex(['customer_key', 'sent_at']);
            $table->dropIndex(['bot_id', 'draft_day']);
            $table->dropIndex(['delivery_status', 'next_attempt_at']);
            $table->dropIndex(['bot_id', 'created_at', 'id']);
        });

        // SQLite ห้าม DROP COLUMN ที่เกี่ยวกับ FOREIGN KEY/unique — ใช้ทาง rebuild ของ SQLite
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildOriginalLeadRecoveryLogsSqlite();

            return;
        }

        Schema::table('lead_recovery_logs', function (Blueprint $table) {
            $table->dropColumn(self::ADDED_LOG_COLUMNS);
            // คืน nullability เดิมของคอลัมน์ส่งจริง — แถวเก่าทุกแถวมีค่า จึงอ่านได้ครบ
            $table->text('message_sent')->nullable(false)->change();
            $table->timestamp('sent_at')->nullable(false)->change();
        });
    }

    /**
     * SQLite ห้าม ALTER TABLE DROP COLUMN บนคอลัมน์ที่เป็นส่วนของ FK หรือ unique ดัชนี
     * — เดินตามขั้นตอนมาตรฐานของ SQLite: เปลี่ยนชื่อตารางเดิม สร้างตารางรูปเดิม
     * คัดลอกเฉพาะคอลัมน์เดิม (แถวส่งจริงเก่าอ่านได้ครบหลัง down) แล้วสลับชื่อ
     */
    private function rebuildOriginalLeadRecoveryLogsSqlite(): void
    {
        Schema::getConnection()->statement('alter table lead_recovery_logs rename to lead_recovery_logs_pre_b2a');

        // ชื่อดัชนีใน SQLite เป็น global — ดัชนีเดิมของตารางที่ถูกเปลี่ยนชื่อต้องหายก่อนสร้างใหม่
        Schema::getConnection()->statement('drop index if exists "lead_recovery_logs_bot_id_sent_at_index"');
        Schema::getConnection()->statement('drop index if exists "lead_recovery_logs_conversation_id_customer_responded_index"');

        // สร้างรูปเดิม — คัดลอกตรงจาก database/migrations/2026_01_12_000003_create_lead_recovery_logs_table.php
        Schema::create('lead_recovery_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->integer('attempt_number');
            $table->string('message_mode', 10); // static, ai
            $table->text('message_sent');
            $table->timestamp('sent_at');
            $table->string('delivery_status', 20)->default('sent'); // sent, failed, blocked
            $table->text('error_message')->nullable();
            $table->boolean('customer_responded')->default(false);
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            // Index for analytics queries
            $table->index(['bot_id', 'sent_at']);

            // Index for response tracking
            $table->index(['conversation_id', 'customer_responded']);
        });

        Schema::getConnection()->statement(
            'insert into "lead_recovery_logs" ("id", "conversation_id", "bot_id", "attempt_number", "message_mode", "message_sent", "sent_at", "delivery_status", "error_message", "customer_responded", "responded_at", "created_at", "updated_at")'
            .' select "id", "conversation_id", "bot_id", "attempt_number", "message_mode", "message_sent", "sent_at", "delivery_status", "error_message", "customer_responded", "responded_at", "created_at", "updated_at"'
            .' from "lead_recovery_logs_pre_b2a"'
        );

        Schema::getConnection()->statement('drop table lead_recovery_logs_pre_b2a');
    }

    /** คอลัมน์ที่ up() เพิ่มบน lead_recovery_logs — ใช้ซ้ำที่ down() */
    private const ADDED_LOG_COLUMNS = [
        'silence_user_message_id',
        'observed_last_message_id',
        'customer_key',
        'sales_started_at',
        'payment_step',
        'transfer_summary_sent',
        'group_code',
        'group_confidence',
        'declined_score',
        'decision_version',
        'context_redacted',
        'sort_answers',
        'check_answers',
        'draft',
        'drafted_at',
        'draft_day',
        'draft_reserved_at',
        'draft_model',
        'owner_action',
        'owner_telegram_user_id',
        'owner_action_at',
        'check_overridden',
        'retry_key',
        'first_push_at',
        'line_request_id',
        'message_id',
        'next_attempt_at',
        'attempt_count',
        'claimed_at',
        'claim_token',
        'reason_code',
        'sort_latency_ms',
        'draft_latency_ms',
        'check_latency_ms',
        'model_cost',
        'usage_totals',
        'replied_within_72h',
        'response_window_closed_at',
        'ordered_within_7d',
        'order_id',
        'ordered_at',
        'order_window_closed_at',
    ];

    /**
     * Postgres ยังต้องล็อกสั้นๆ ตอน ALTER TABLE — จำกัดไว้ระดับ transaction-local เท่านั้น
     * (SQLite ของ environment ทดสอบไม่มีคำสั่งเหล่านี้ ห้ามยิง)
     */
    private function guardLockTime(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::getConnection()->statement("set local lock_timeout = '5s'");
        Schema::getConnection()->statement("set local statement_timeout = '30s'");
    }
};
