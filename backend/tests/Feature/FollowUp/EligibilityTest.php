<?php

namespace Tests\Feature\FollowUp;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\FollowUpSalesSignal;
use App\Models\LeadRecoveryLog;
use App\Models\User;
use App\Services\FollowUp\FollowUpEligibility;
use App\Services\FollowUp\FollowUpSalesProjector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B2b — ประตู step 1 (code gate): ห้องไหนตามได้ ใน ONE SQL statement
 * บน read model ไร้ข้อความ (follow_up_sales_signals) — plan §3.3 lines 143-157
 *
 * สัญญาจาก b2b.md:
 * - ทุกเงื่อนไขในตาราง = predicate ของ ONE SQL statement ห้ามกรองด้วย PHP
 * - นอก 08:00-22:00 Bangkok หรือ capabilities ติด block = query constant-false
 * - projection เก่ากว่าต้นจังหวะเช็ค หรือ id ไม่ตรงตารางจริง = ไม่ผ่านเด็ดขาด
 * - query() ต้องยิง SELECT ครั้งเดียวไม่ว่าห้องเข้าเกณฑ์กี่ห้อง
 */
class EligibilityTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00';

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW, 'Asia/Bangkok');
    }

    private function at(string $localBangkok): CarbonImmutable
    {
        return CarbonImmutable::parse($localBangkok, 'Asia/Bangkok');
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * ห้องที่ผ่านทุกเงื่อนไข (ยังไม่เคยซื้อ → span = 30 วันล่าสุด):
     * m1 ลูกค้า 08:55 / m2 บอท 08:56 / m3 ลูกค้า 08:57 / m4 บอท 08:58 / m5 ลูกค้า 08:59
     * last message 08:59 เงียบ 25 ชม. > 24 ชม. (ไม่มี payment signal → สายขาย)
     * $prepend = [[sender, at, content], ...] แทรกก่อนข้อความทั้งหมด (id น้อยกว่า)
     */
    private function seedBase(array $prepend = []): array
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-6-luna',
        ]);

        config(['customer_follow_up.bot_ids' => [$bot->id]]);
        config(['customer_follow_up.admin_web_url' => 'https://admin.example.test']);
        config(['services.telegram_alert.secret' => 'webhook-secret-test']);

        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งเตือน',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => ['access_token' => 'tok-123', 'chat_id' => '999', 'authorized_user_ids' => ['555']],
        ]);

        DB::table('bot_settings')->insert([
            'bot_id' => $bot->id,
            'follow_up_enabled' => true,
            'follow_up_mode' => 'approve',
            'follow_up_started_at' => '2026-10-17 10:00:00',
            'follow_up_activation_message_id' => 0,
            'follow_up_activated_at' => '2026-10-17 10:00:00',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        $room = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => 'U111',
        ]);

        foreach ($prepend as [$sender, $at, $content]) {
            $this->addMessage($room->id, $sender, $at, $content);
        }

        $this->addMessage($room->id, 'user', '2026-11-15 08:55:00', 'สนใจ Nolimit Personal ครับ');
        $this->addMessage($room->id, 'bot', '2026-11-15 08:56:00', 'ต้องการกี่ตัวครับ');
        $this->addMessage($room->id, 'user', '2026-11-15 08:57:00', 'เอา 2 ตัวครับ');
        $this->addMessage($room->id, 'bot', '2026-11-15 08:58:00', 'ได้ครับ กำลังเตรียมสินค้าให้ครับ');
        $anchorId = $this->addMessage($room->id, 'user', '2026-11-15 08:59:00', 'ประเมินราคาให้หน่อยครับ');

        FollowUpSalesProjector::refresh($room, $this->now());

        return ['bot' => $bot, 'room' => $room, 'anchorId' => $anchorId];
    }

    private function addMessage(int $conversationId, string $sender, string $at, string $content): int
    {
        return DB::table('messages')->insertGetId([
            'conversation_id' => $conversationId,
            'sender' => $sender,
            'content' => $content,
            'type' => 'text',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function addOrder(int $botId, int $conversationId, string $at): int
    {
        return DB::table('orders')->insertGetId([
            'bot_id' => $botId,
            'conversation_id' => $conversationId,
            'total_amount' => 100,
            'status' => 'completed',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function addSlip(int $botId, ?int $conversationId, string $status, string $at): int
    {
        return DB::table('slip_verifications')->insertGetId([
            'bot_id' => $botId,
            'conversation_id' => $conversationId,
            'status' => $status,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function addLog(Conversation $room, ?int $anchorId, ?int $observedId, string $status, array $extra = []): int
    {
        return DB::table('lead_recovery_logs')->insertGetId(array_merge([
            'conversation_id' => $room->id,
            'bot_id' => $room->bot_id,
            'attempt_number' => 1,
            'message_mode' => 'static',
            'message_sent' => null,
            'sent_at' => null,
            'delivery_status' => $status,
            'silence_user_message_id' => $anchorId,
            'observed_last_message_id' => $observedId,
            'customer_key' => $this->key($room),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $extra));
    }

    private function key(Conversation $room): string
    {
        return hash('sha256', $room->bot_id.'|line|'.$room->external_customer_id);
    }

    private function eligibleCount(Bot $bot, CarbonImmutable $now, ?int $ownLogId = null): int
    {
        return FollowUpEligibility::query($bot, $now, $ownLogId)->get()->count();
    }

    /** เทียบของแต่ละเงื่อนไข: fixture สะอาดครั้งแรกต้องได้ 1 ห้อง แล้วแต่ละเคสต้องได้ 0 */
    private function assertCaseExcludes(string $label, \Closure $mutate): void
    {
        $seed = $this->seedBase();
        $mutate($seed['bot'], $seed['room']);

        FollowUpSalesProjector::refresh($seed['room'], $this->now());

        $count = $this->eligibleCount($seed['bot'], $this->now());
        $this->assertSame(0, $count, "เคส \"{$label}\" ต้องไม่มีห้องผ่านประตู");
    }

    // ------------------------------------------------------------------
    // 1) ทุกเงื่อนไขที่ล้มเหลวทีละตัว (plan lines 143-157)
    // ------------------------------------------------------------------

    #[Test]
    public function test_gate_rejects_each_failed_conjunct(): void
    {
        // control: fixture สะอาดต้องได้ห้องเดียวพอดี
        $seed = $this->seedBase();
        $this->assertSame(1, $this->eligibleCount($seed['bot'], $this->now()), 'control: ห้องสะอาดต้องผ่าน 1 ห้อง');

        $cases = [
            'bot_follow_up_switch_off' => function (Bot $bot): void {
                DB::table('bot_settings')->where('bot_id', $bot->id)->update(['follow_up_enabled' => false]);
            },
            'bot_status_paused' => function (Bot $bot): void {
                $bot->update(['status' => 'paused']);
            },
            'room_channel_facebook' => function (Bot $bot, Conversation $room): void {
                $room->update(['channel_type' => 'facebook']);
            },
            'room_status_closed' => function (Bot $bot, Conversation $room): void {
                $room->update(['status' => 'closed']);
            },
            'room_handover_on' => function (Bot $bot, Conversation $room): void {
                $room->update(['is_handover' => true]);
            },
            'room_soft_deleted' => function (Bot $bot, Conversation $room): void {
                $room->delete();
            },
            'empty_external_customer_id' => function (Bot $bot, Conversation $room): void {
                $room->update(['external_customer_id' => '']);
            },
            'bot_settings_row_missing' => function (Bot $bot): void {
                DB::table('bot_settings')->where('bot_id', $bot->id)->delete();
            },
            'no_user_messages' => function (Bot $bot, Conversation $room): void {
                DB::table('messages')->where('conversation_id', $room->id)->where('sender', 'user')->delete();
            },
            'single_user_message' => function (Bot $bot, Conversation $room): void {
                DB::table('messages')->where('conversation_id', $room->id)->where('sender', 'user')
                    ->where('id', '!=', DB::table('messages')->where('conversation_id', $room->id)->where('sender', 'user')->max('id'))
                    ->delete();
            },
            'order_after_last_user_message' => function (Bot $bot, Conversation $room): void {
                $this->addOrder($bot->id, $room->id, '2026-11-15 09:30:00');
            },
            'passed_slip_after_last_user_message' => function (Bot $bot, Conversation $room): void {
                $this->addSlip($bot->id, $room->id, 'passed', '2026-11-15 09:30:00');
            },
            'manual_confirmed_slip_after_last_user_message' => function (Bot $bot, Conversation $room): void {
                $this->addSlip($bot->id, $room->id, 'manual_confirmed', '2026-11-15 09:30:00');
            },
            'recent_send_same_customer_within_7d' => function (Bot $bot, Conversation $room): void {
                $this->addLog($room, null, null, 'sent', ['sent_at' => '2026-11-14 10:00:00']);
            },
            'pending_log_same_silence' => function (Bot $bot, Conversation $room): void {
                $anchorId = (int) DB::table('messages')->where('conversation_id', $room->id)->where('sender', 'user')->max('id');
                $this->addLog($room, $anchorId, $anchorId, 'pending');
            },
        ];

        foreach ($cases as $label => $mutate) {
            $this->assertCaseExcludes($label, $mutate);
        }

        // ข้อความแฝง credential ต้องอยู่ใน span (id ก่อนข้อความอื่น เพื่อไม่กลายเป็น anchor)
        $seed = $this->seedBase([['user', '2026-11-14 09:00:00', 'foo@mail.com|p@ssw0rd9']]);
        $signal = FollowUpSalesSignal::query()->where('conversation_id', $seed['room']->id)->first();
        $this->assertTrue($signal->sensitive_account_data, 'pre-condition: projector ต้องติดธง sensitive_account_data');
        $this->assertSame(0, $this->eligibleCount($seed['bot'], $this->now()), 'เคส sensitive_account_data ต้องไม่ผ่าน');
    }

    // ------------------------------------------------------------------
    // 2) span การขายเริ่มใหม่ที่ออเดอร์ล่าสุด
    // ------------------------------------------------------------------

    #[Test]
    public function test_current_span_resets_at_latest_order(): void
    {
        // (ก) ออเดอร์กลางห้อง: ข้อความลูกค้าหลังออเดอร์เหลือ 1 → ไม่ผ่าน
        $seed = $this->seedBase();
        $this->addOrder($seed['bot']->id, $seed['room']->id, '2026-11-15 08:57:30');
        FollowUpSalesProjector::refresh($seed['room'], $this->now());
        $this->assertSame(0, $this->eligibleCount($seed['bot'], $this->now()));

        // (ข) เพิ่มข้อความหลังออเดอร์อีก 1 → ครบ 2 → ผ่าน
        $this->addMessage($seed['room']->id, 'user', '2026-11-15 09:00:00', 'สะดวกครับ');
        FollowUpSalesProjector::refresh($seed['room'], $this->now());
        $this->assertSame(1, $this->eligibleCount($seed['bot'], $this->now()));

        // (ค) ไม่เคยซื้อ: ข้อความเก่ากว่า 30 วันไม่นับ
        $old = $this->seedBase();
        DB::table('messages')->where('conversation_id', $old['room']->id)->delete();
        $this->addMessage($old['room']->id, 'user', '2026-10-01 09:00:00', 'สนใจครับ');
        $this->addMessage($old['room']->id, 'user', '2026-10-02 09:00:00', 'ยังสนใจครับ');
        FollowUpSalesProjector::refresh($old['room'], $this->now());
        $this->assertSame(0, FollowUpSalesSignal::query()->where('conversation_id', $old['room']->id)->first()->customer_message_count);
        $this->assertSame(0, $this->eligibleCount($old['bot'], $this->now()));

        // (ง) ออเดอร์ในห้องอื่นของลูกค้าเดียวกัน (บอทเดียวกัน) ก็ปิดห้องนี้ด้วย
        $other = $this->seedBase();
        $roomB = Conversation::factory()->line()->create([
            'bot_id' => $other['bot']->id,
            'external_customer_id' => $other['room']->external_customer_id,
        ]);
        $this->addOrder($other['bot']->id, $roomB->id, '2026-11-15 09:30:00');
        FollowUpSalesProjector::refresh($other['room'], $this->now());
        $this->assertSame(0, $this->eligibleCount($other['bot'], $this->now()));

        // (จ) สลิปผ่านในห้องอื่นของลูกค้าเดียวกันก็ปิด
        $slipRoom = $this->seedBase();
        $roomC = Conversation::factory()->line()->create([
            'bot_id' => $slipRoom['bot']->id,
            'external_customer_id' => $slipRoom['room']->external_customer_id,
        ]);
        $this->addSlip($slipRoom['bot']->id, $roomC->id, 'passed', '2026-11-15 09:30:00');
        FollowUpSalesProjector::refresh($slipRoom['room'], $this->now());
        $this->assertSame(0, $this->eligibleCount($slipRoom['bot'], $this->now()));

        // (ฉ) บอทอื่น ลูกค้า id เดียวกัน ต้องไม่ปิดห้องบอทนี้
        $cross = $this->seedBase();
        $bot2 = Bot::factory()->active()->line()->create(['user_id' => $cross['bot']->user_id, 'primary_chat_model' => 'openai/gpt-6-luna']);
        $roomD = Conversation::factory()->line()->create([
            'bot_id' => $bot2->id,
            'external_customer_id' => $cross['room']->external_customer_id,
        ]);
        $this->addOrder($bot2->id, $roomD->id, '2026-11-15 09:30:00');
        FollowUpSalesProjector::refresh($cross['room'], $this->now());
        $this->assertSame(1, $this->eligibleCount($cross['bot'], $this->now()));
    }

    // ------------------------------------------------------------------
    // 3) สัญญาณจ่ายเงิน 3 อย่าง และยืนยันปลอม
    // ------------------------------------------------------------------

    #[Test]
    public function test_three_payment_signals_and_no_false_confirmation(): void
    {
        // (ก) ข้อความยืนยันขั้น 2 จริง (รวม...บาท + พิมพ์ ยืนยัน) → 3 ชม.
        $confirm = $this->seedBase();
        $this->addMessage($confirm['room']->id, 'bot', '2026-11-15 08:58:30', 'รวมทั้งหมด 100 บาท พิมพ์ ยืนยัน ได้เลยครับ');
        FollowUpSalesProjector::refresh($confirm['room'], $this->now());
        $this->assertTrue(FollowUpSalesSignal::query()->where('conversation_id', $confirm['room']->id)->first()->payment_step);
        $this->assertSame(0, $this->eligibleCount($confirm['bot'], $this->at('2026-11-16 11:58:29')), 'ยังไม่ครบ 3 ชม.');
        FollowUpSalesProjector::refresh($confirm['room'], $this->at('2026-11-16 11:58:30'));
        $this->assertSame(1, $this->eligibleCount($confirm['bot'], $this->at('2026-11-16 11:58:30')), 'ครบ 3 ชม. พอดี = ผ่าน');

        // (ข) แจ้งเตือน Support ล่าช้า → 3 ชม.
        $support = $this->seedBase();
        $this->addMessage($support['room']->id, 'bot', '2026-11-15 08:58:30', '[แจ้งเตือน Support] ทีมซัพพอร์ตวันนี้นานกว่าปกติ ตกลงไหมครับ');
        FollowUpSalesProjector::refresh($support['room'], $this->at('2026-11-15 11:58:29'));
        $this->assertSame(0, $this->eligibleCount($support['bot'], $this->at('2026-11-15 11:58:29')));
        FollowUpSalesProjector::refresh($support['room'], $this->at('2026-11-15 11:58:30'));
        $this->assertSame(1, $this->eligibleCount($support['bot'], $this->at('2026-11-15 11:58:30')));

        // (ค) สรุปยอดโอน (transfer summary) → 3 ชม. + transfer_summary_sent = true
        $summary = $this->seedBase();
        $this->addMessage($summary['room']->id, 'bot', '2026-11-15 08:58:30', 'สรุปยอดโอน 100 บาท เข้าบช. 223-3-24880-3');
        FollowUpSalesProjector::refresh($summary['room'], $this->now());
        $signal = FollowUpSalesSignal::query()->where('conversation_id', $summary['room']->id)->first();
        $this->assertTrue($signal->payment_step);
        $this->assertTrue($signal->transfer_summary_sent);
        FollowUpSalesProjector::refresh($summary['room'], $this->at('2026-11-15 11:58:29'));
        $this->assertSame(0, $this->eligibleCount($summary['bot'], $this->at('2026-11-15 11:58:29')));
        FollowUpSalesProjector::refresh($summary['room'], $this->at('2026-11-15 11:58:30'));
        $this->assertSame(1, $this->eligibleCount($summary['bot'], $this->at('2026-11-15 11:58:30')));

        // (ง) "ไม่ต้องยืนยันตัวตน" ล้วน = ไม่ใช่ signal จ่ายเงิน → ต้องรอ 24 ชม.
        $falseConfirm = $this->seedBase();
        $this->addMessage($falseConfirm['room']->id, 'bot', '2026-11-15 08:58:30', 'ไม่ต้องยืนยันตัวตนเพิ่มครับ ข้อมูลปลอดภัย');
        FollowUpSalesProjector::refresh($falseConfirm['room'], $this->now());
        $signal = FollowUpSalesSignal::query()->where('conversation_id', $falseConfirm['room']->id)->first();
        $this->assertFalse($signal->payment_step, 'ข้อความที่แค่เอ่ยถึง ยืนยัน ต้องไม่ติด payment_step');
        $this->assertSame(0, $this->eligibleCount($falseConfirm['bot'], $this->at('2026-11-15 11:58:30')), 'อายุ 3 ชม. ยังไม่ผ่านสายขาย');
        FollowUpSalesProjector::refresh($falseConfirm['room'], $this->at('2026-11-16 08:58:30'));
        $this->assertSame(1, $this->eligibleCount($falseConfirm['bot'], $this->at('2026-11-16 08:58:30')), 'ครบ 24 ชม. = ผ่าน');

        // (จ) signal ก่อนออเดอร์ล่าสุดไม่นับ (span เริ่มที่ออเดอร์)
        $preOrder = $this->seedBase();
        DB::table('messages')->where('conversation_id', $preOrder['room']->id)->delete();
        $this->addMessage($preOrder['room']->id, 'user', '2026-11-15 08:00:00', 'เอา 2 ตัวครับ');
        $this->addMessage($preOrder['room']->id, 'bot', '2026-11-15 08:05:00', 'รวมทั้งหมด 100 บาท พิมพ์ ยืนยัน ได้เลยครับ');
        $this->addOrder($preOrder['bot']->id, $preOrder['room']->id, '2026-11-15 08:30:00');
        $this->addMessage($preOrder['room']->id, 'user', '2026-11-15 08:50:00', 'โอนแล้วนะครับ');
        $this->addMessage($preOrder['room']->id, 'bot', '2026-11-15 08:55:00', 'ได้ครับ');
        $this->addMessage($preOrder['room']->id, 'user', '2026-11-15 08:59:00', 'ขอบคุณครับ');
        FollowUpSalesProjector::refresh($preOrder['room'], $this->now());
        $signal = FollowUpSalesSignal::query()->where('conversation_id', $preOrder['room']->id)->first();
        $this->assertFalse($signal->payment_step, 'ยืนยันก่อนออเดอร์ต้องไม่นับ');
        $this->assertSame(0, $this->eligibleCount($preOrder['bot'], $this->at('2026-11-15 11:59:00')), 'ที่ 3 ชม. ยังไม่ผ่าน (สายขาย)');
        FollowUpSalesProjector::refresh($preOrder['room'], $this->at('2026-11-16 08:59:00'));
        $this->assertSame(1, $this->eligibleCount($preOrder['bot'], $this->at('2026-11-16 08:59:00')), 'ที่ 24 ชม. ผ่าน');
    }

    // ------------------------------------------------------------------
    // 4) ขอบเขตเวลากรุงเทพฯ (ชั่วโมง + งบรายวันผ่าน log rows)
    // ------------------------------------------------------------------

    #[Test]
    public function test_bangkok_boundaries(): void
    {
        $seed = $this->seedBase();

        // ข้อความต้องเก่ากว่า 24 ชม. ที่จุดตรวจที่เร็วที่สุด (07:59:59) — ย้าย timeline ไป 14 พ.ย.
        DB::table('messages')->where('conversation_id', $seed['room']->id)->delete();
        $this->addMessage($seed['room']->id, 'user', '2026-11-14 08:55:00', 'สนใจ Nolimit Personal ครับ');
        $this->addMessage($seed['room']->id, 'bot', '2026-11-14 08:56:00', 'ต้องการกี่ตัวครับ');
        $this->addMessage($seed['room']->id, 'user', '2026-11-14 08:57:00', 'เอา 2 ตัวครับ');
        $this->addMessage($seed['room']->id, 'bot', '2026-11-14 08:58:00', 'ได้ครับ กำลังเตรียมสินค้าให้ครับ');
        $this->addMessage($seed['room']->id, 'user', '2026-11-14 08:59:00', 'ประเมินราคาให้หน่อยครับ');

        foreach ([['2026-11-16 07:59:59', 0], ['2026-11-16 08:00:00', 1], ['2026-11-16 21:59:59', 1], ['2026-11-16 22:00:00', 0]] as [$at, $expected]) {
            FollowUpSalesProjector::refresh($seed['room'], $this->at($at));
            $this->assertSame($expected, $this->eligibleCount($seed['bot'], $this->at($at)), "เวลา {$at} ต้องได้ {$expected}");
        }

        // 23:59:59 / 00:00:00 — นอกชั่วโมงส่ง ต้องได้ 0 ทั้งคู่
        foreach (['2026-11-16 23:59:59', '2026-11-17 00:00:00'] as $at) {
            FollowUpSalesProjector::refresh($seed['room'], $this->at($at));
            $this->assertSame(0, $this->eligibleCount($seed['bot'], $this->at($at)), "เวลา {$at} นอกชั่วโมงส่ง");
        }

        // งบรายวันเป็นการ "อ่าน" log rows ตามวันที่กรุงเทพฯ — 10 วันนี้ = 0, 9 = ผ่าน, เมื่อวานไม่นับ
        $trash = Conversation::factory()->line()->create(['bot_id' => $seed['bot']->id, 'external_customer_id' => 'Uthrow']);
        $draftRows = fn (string $day): array => [
            'conversation_id' => $trash->id,
            'bot_id' => $seed['bot']->id,
            'attempt_number' => 1,
            'message_mode' => 'static',
            'message_sent' => null,
            'delivery_status' => 'drafting',
            'drafted_at' => $day.' 09:00:00',
            'draft_day' => $day,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ];

        FollowUpSalesProjector::refresh($seed['room'], $this->now());
        foreach (range(1, 10) as $i) {
            DB::table('lead_recovery_logs')->insert($draftRows('2026-11-16'));
            $this->assertSame($i === 10 ? 0 : 1, $this->eligibleCount($seed['bot'], $this->now()), "งบร่างวันนี้ {$i}/10");
        }

        DB::table('lead_recovery_logs')->where('draft_day', '2026-11-16')->update(['draft_day' => '2026-11-15']);
        $this->assertSame(1, $this->eligibleCount($seed['bot'], $this->now()), 'แถวเมื่อวานต้องไม่นับงบวันนี้');
    }

    // ------------------------------------------------------------------
    // 5) คัตออฟแบ็คล็อกตอนเปิดครั้งแรก ตายตัวที่ activation
    // ------------------------------------------------------------------

    /** ห้องที่เงียบก่อน activation: ข้อความ 3 ก่อนเปิด, activation high-water = ข้อความสุดท้าย */
    private function seedBacklogRoom(string $anchorAt, string $activatedAt, string $now): array
    {
        $seed = $this->seedBase();
        DB::table('messages')->where('conversation_id', $seed['room']->id)->delete();

        $this->addMessage($seed['room']->id, 'user', CarbonImmutable::parse($anchorAt)->subSeconds(60)->format('Y-m-d H:i:s'), 'สนใจครับ');
        $this->addMessage($seed['room']->id, 'bot', CarbonImmutable::parse($anchorAt)->subSeconds(30)->format('Y-m-d H:i:s'), 'ได้ครับ');
        $anchorId = $this->addMessage($seed['room']->id, 'user', $anchorAt, 'ประเมินให้หน่อยครับ');

        DB::table('bot_settings')->where('bot_id', $seed['bot']->id)->update([
            'follow_up_started_at' => $activatedAt,
            'follow_up_activation_message_id' => $anchorId,
            'follow_up_activated_at' => $activatedAt,
        ]);

        FollowUpSalesProjector::refresh($seed['room'], CarbonImmutable::parse($now, 'Asia/Bangkok'));

        return [...$seed, 'anchorId' => $anchorId, 'now' => $now];
    }

    #[Test]
    public function test_launch_backlog_cutoff_is_fixed(): void
    {
        // อายุเงียบ "ณ ตอนเปิด" ตัดสิน: < 24 ชม. หรือ > 7 วัน = ไม่เก็บ, 1-7 วัน = เก็บ (ขอบรวมทั้งสองข้าง)
        $cases = [
            ['23 ชม. 59 นาที ก่อนเปิด', '2026-11-14 10:00:00', '2026-11-15 09:59:00', '2026-11-16 10:59:00', 0],
            ['1 วันก่อนเปิดพอดี', '2026-11-14 09:59:00', '2026-11-15 09:59:00', '2026-11-16 10:59:00', 1],
            ['7 วันก่อนเปิดพอดี', '2026-11-08 09:59:00', '2026-11-15 09:59:00', '2026-11-16 10:59:00', 1],
            ['7 วัน + 1 วินาที', '2026-11-08 09:58:59', '2026-11-15 09:59:00', '2026-11-16 10:59:00', 0],
        ];
        foreach ($cases as [$label, $anchorAt, $activatedAt, $now, $expected]) {
            $seed = $this->seedBacklogRoom($anchorAt, $activatedAt, $now);
            $count = $this->eligibleCount($seed['bot'], $this->at($now));
            $this->assertSame($expected, $count, "แบ็คล็อก {$label} ต้องได้ {$expected}");
        }

        // anchor ใหม่หลังเปิด: ใช้ 3 ชม. (สายจ่าย) ไม่เข้าหน้าต่างแบ็คล็อก
        $newTurn = $this->seedBase();
        DB::table('bot_settings')->where('bot_id', $newTurn['bot']->id)->update([
            'follow_up_activated_at' => '2026-11-16 08:00:00',
            'follow_up_activation_message_id' => (int) DB::table('messages')->where('conversation_id', $newTurn['room']->id)->max('id'),
        ]);
        $this->addMessage($newTurn['room']->id, 'user', '2026-11-16 09:00:00', 'เอา 2 ตัวครับ');
        $this->addMessage($newTurn['room']->id, 'bot', '2026-11-16 09:01:00', 'รวมทั้งหมด 100 บาท พิมพ์ ยืนยัน ได้เลยครับ');
        FollowUpSalesProjector::refresh($newTurn['room'], $this->at('2026-11-16 12:01:00'));
        $this->assertSame(1, $this->eligibleCount($newTurn['bot'], $this->at('2026-11-16 12:01:00')), 'เทิร์นใหม่หลังเปิด ครบ 3 ชม. หลังข้อความสุดท้าย (09:01) = ผ่าน');
        FollowUpSalesProjector::refresh($newTurn['room'], $this->at('2026-11-16 12:00:59'));
        $this->assertSame(0, $this->eligibleCount($newTurn['bot'], $this->at('2026-11-16 12:00:59')), '1 วินาทีก่อนครบ 3 ชม. ยังไม่ผ่าน');

        // ปิดสวิตช์ = ไม่ผ่าน เปิดกลับ (cutoff คงเดิม) = ผ่านเหมือนเดิม
        $restart = $this->seedBacklogRoom('2026-11-14 09:59:00', '2026-11-15 09:59:00', '2026-11-16 10:59:00');
        DB::table('bot_settings')->where('bot_id', $restart['bot']->id)->update(['follow_up_enabled' => false]);
        $this->assertSame(0, $this->eligibleCount($restart['bot'], $this->at('2026-11-16 10:59:00')));
        DB::table('bot_settings')->where('bot_id', $restart['bot']->id)->update(['follow_up_enabled' => true]);
        $this->assertSame(1, $this->eligibleCount($restart['bot'], $this->at('2026-11-16 10:59:00')));

        // แบ็คล็อกที่แก่เกิน ไม่กลายเป็นเทิร์นใหม่วันหลัง
        $oldest = $this->seedBacklogRoom('2026-11-07 09:59:00', '2026-11-15 09:59:00', '2026-11-16 10:59:00');
        $this->assertSame(0, $this->eligibleCount($oldest['bot'], $this->at('2026-11-16 10:59:00')));
        FollowUpSalesProjector::refresh($oldest['room'], $this->at('2026-12-15 09:59:00'));
        $this->assertSame(0, $this->eligibleCount($oldest['bot'], $this->at('2026-12-15 09:59:00')), 'แบ็คล็อกเกิน 7 วัน ณ เปิด ต้องไม่ผ่านตลอดไป');
    }

    // ------------------------------------------------------------------
    // 6) เงียบเดิมถูกตามแล้ว + ช่องว่างลูกค้า 7 วัน (เทียบผ่าน log rows ที่ seed)
    // ------------------------------------------------------------------

    #[Test]
    public function test_same_silence_and_customer_gap(): void
    {
        // เงียบเดิม (anchor เดียวกัน) มี log สถานะติดขัดแล้ว → ไม่ผ่าน
        foreach (['pending' => 0, 'approved' => 0, 'sending' => 0, 'retry' => 0, 'sent' => 0, 'skipped' => 0, 'expired' => 0, 'cancelled' => 0, 'excluded' => 0, 'deferred' => 1, 'candidate' => 1] as $status => $expected) {
            $seed = $this->seedBase();
            $this->addLog($seed['room'], $seed['anchorId'], $seed['anchorId'], $status);
            $this->assertSame($expected, $this->eligibleCount($seed['bot'], $this->now()), "สถานะ {$status} ของเงียบเดิมต้องได้ {$expected}");
        }

        // ownLogId ยกเว้นได้เฉพาะแถวตัวเองที่ยังไม่ sent (unique ต่อ silence → ห้องละ log เดียว)
        $seed = $this->seedBase();
        $logId = $this->addLog($seed['room'], $seed['anchorId'], $seed['anchorId'], 'pending');
        $this->assertSame(1, $this->eligibleCount($seed['bot'], $this->now(), $logId), 'ownLogId ยกเว้นแถวตัวเอง');

        $sentSeed = $this->seedBase();
        $sentId = $this->addLog($sentSeed['room'], $sentSeed['anchorId'], $sentSeed['anchorId'], 'sent', ['sent_at' => self::NOW]);
        $this->assertSame(0, $this->eligibleCount($sentSeed['bot'], $this->now(), $sentId), 'แถว sent ห้ามยกเว้นด้วย ownLogId');

        // ช่องว่างลูกค้า 7 วัน: log ต่าง anchor ของลูกค้าเดียวกัน (key ตรงกัน)
        $seedWithLog = fn (?string $sentAt, string $status = 'sent', ?string $firstPushAt = null) => tap($this->seedBase(), function ($seed) use ($sentAt, $status, $firstPushAt) {
            $extra = [];
            if ($sentAt !== null) {
                $extra['sent_at'] = $sentAt;
            }
            if ($firstPushAt !== null) {
                $extra['first_push_at'] = $firstPushAt;
            }
            $this->addLog($seed['room'], null, null, $status, $extra);
        });

        $seed = $seedWithLog('2026-11-09 10:01:00'); // 6 วัน 23:59 ก่อน now
        $this->assertSame(0, $this->eligibleCount($seed['bot'], $this->now()), 'ส่งไป 6d23:59 ยังไม่ครบ 7 วัน');

        $seed = $seedWithLog('2026-11-09 10:00:00'); // 7 วันพอดี
        $this->assertSame(1, $this->eligibleCount($seed['bot'], $this->now()), 'ครบ 7 วันพอดี = ผ่าน');

        $seed = $this->seedBase(); // ไม่มี cap ตลอดชีพ: ส่งไปแล้ว 3 ครั้ง ทุกครั้งเก่ากว่า 7 วัน
        foreach (['2026-10-27 10:00:00', '2026-11-01 10:00:00', '2026-11-06 10:00:00'] as $sentAt) {
            $this->addLog($seed['room'], null, null, 'sent', ['sent_at' => $sentAt]);
        }
        $this->assertSame(1, $this->eligibleCount($seed['bot'], $this->now()));

        // กำลังส่ง/กำลัง retry ที่มี first_push_at นับเป็น "เพิ่งส่ง" (acceptance ไม่รู้ผล)
        $seed = $seedWithLog(null, 'sending', '2026-11-15 09:00:00');
        $this->assertSame(0, $this->eligibleCount($seed['bot'], $this->now()));

        $seed = $seedWithLog(null, 'retry', '2026-11-15 09:00:00');
        $this->assertSame(0, $this->eligibleCount($seed['bot'], $this->now()));

        $seed = $seedWithLog(null, 'sending');
        $this->assertSame(1, $this->eligibleCount($seed['bot'], $this->now()), 'sending ที่ยังไม่มี first_push_at ไม่นับ');

        $seed = $this->seedBase();
        $inFlight = $this->addLog($seed['room'], null, null, 'sending', ['first_push_at' => '2026-11-15 09:00:00']);
        $this->assertSame(1, $this->eligibleCount($seed['bot'], $this->now(), $inFlight), 'ownLogId ยกเว้นแถวกำลังส่งของตัวเอง');
    }

    // ------------------------------------------------------------------
    // 7) projection เก่า / ข้อความใหม่ / ออเดอร์ใหม่ = ไม่ผ่านเด็ดขาด
    // ------------------------------------------------------------------

    #[Test]
    public function test_stale_projection_or_new_message_never_passes(): void
    {
        $seed = $this->seedBase();
        $this->assertSame(1, $this->eligibleCount($seed['bot'], $this->now()));

        // ฉีดข้อความลูกค้าใหม่ โดยยังไม่ refresh → id ไม่ตรงตารางจริง → ไม่ผ่าน
        $this->addMessage($seed['room']->id, 'user', '2026-11-16 09:59:00', 'ตามอีกครับ');
        $this->assertSame(0, $this->eligibleCount($seed['bot'], $this->now()));

        // refresh ครบแล้วผ่านกลับ (แสดงว่าที่ไม่ผ่านเพราะ stale จริง ไม่ใช่ fixture พัง)
        DB::table('messages')->where('conversation_id', $seed['room']->id)->where('created_at', '2026-11-16 09:59:00')->delete();
        FollowUpSalesProjector::refresh($seed['room'], $this->now());
        $this->assertSame(1, $this->eligibleCount($seed['bot'], $this->now()));

        // projection เก่ากว่าต้นจังหวะเช็ค 1 วินาที → ไม่ผ่าน แม้ข้อมูลตรง
        $later = $this->now()->addSeconds(1);
        $this->assertSame(0, $this->eligibleCount($seed['bot'], $later));
        FollowUpSalesProjector::refresh($seed['room'], $later);
        $this->assertSame(1, $this->eligibleCount($seed['bot'], $later));

        // ฉีดออเดอร์ก่อนข้อความลูกค้าสุดท้าย โดยไม่ refresh → latest_order_id ไม่ NULL-safe ตรง → ไม่ผ่าน
        $this->addOrder($seed['bot']->id, $seed['room']->id, '2026-11-15 08:30:00');
        $this->assertSame(0, $this->eligibleCount($seed['bot'], $later));
        FollowUpSalesProjector::refresh($seed['room'], $later);
        $this->assertSame(1, $this->eligibleCount($seed['bot'], $later), 'ออเดอร์ก่อนข้อความลูกค้าสุดท้าย reset span แล้วยังผ่าน');
    }

    // ------------------------------------------------------------------
    // 8) ONE SELECT และ relations ผูกพัน ไม่ว่าผลลัพธ์ 1 ห้องหรือ 10
    // ------------------------------------------------------------------

    #[Test]
    public function test_gate_is_one_query_and_relations_are_bounded(): void
    {
        $seed = $this->seedBase();

        $query = FollowUpEligibility::query($seed['bot'], $this->now());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rooms = $query->get();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $rooms);
        $this->assertCount(1, $log, 'ต้องยิง SELECT เดียว: '.json_encode(array_column($log, 'query')));
        $this->assertStringContainsString('conversations', strtolower($log[0]['query']));

        // 10 ห้องที่ผ่านเกณฑ์ บอทเดียวกัน → ยัง SELECT เดียว
        foreach (range(1, 10) as $i) {
            $room = Conversation::factory()->line()->create([
                'bot_id' => $seed['bot']->id,
                'external_customer_id' => 'U100'.$i,
            ]);
            $this->addMessage($room->id, 'user', '2026-11-15 08:55:00', 'สนใจครับ');
            $this->addMessage($room->id, 'bot', '2026-11-15 08:56:00', 'ต้องการกี่ตัวครับ');
            $this->addMessage($room->id, 'user', '2026-11-15 08:57:00', 'เอา 2 ตัวครับ');
            $this->addMessage($room->id, 'bot', '2026-11-15 08:58:00', 'ได้ครับ');
            $this->addMessage($room->id, 'user', '2026-11-15 08:59:00', 'ประเมินราคาให้หน่อยครับ');
            FollowUpSalesProjector::refresh($room, $this->now());
        }

        $query = FollowUpEligibility::query($seed['bot'], $this->now());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rooms = $query->get();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(11, $rooms, 'ห้องฐาน + 10 ที่เพิ่ม = 11');
        $this->assertCount(1, $log, '11 ห้องก็ต้องยิง SELECT เดียว: '.json_encode(array_column($log, 'query')));
    }

    // ------------------------------------------------------------------
    // 9) recheck: null เมื่อห้องยังผ่าน / reason code เสถียรเมื่อไม่ผ่าน
    // ------------------------------------------------------------------

    #[Test]
    public function test_recheck_returns_null_for_unchanged_room(): void
    {
        $seed = $this->seedBase();
        $logId = $this->addLog($seed['room'], $seed['anchorId'], $seed['anchorId'], 'candidate');
        $log = LeadRecoveryLog::query()->findOrFail($logId);

        $this->assertSame(null, FollowUpEligibility::recheck($log, $this->now()));
    }

    #[Test]
    public function test_recheck_reason_codes(): void
    {
        $codes = [];

        // ลูกค้าพิมพ์ใหม่
        $seed = $this->seedBase();
        $log = LeadRecoveryLog::query()->findOrFail($this->addLog($seed['room'], $seed['anchorId'], $seed['anchorId'], 'candidate'));
        $this->addMessage($seed['room']->id, 'user', '2026-11-16 09:59:00', 'ตามอีกครับ');
        $codes[] = FollowUpEligibility::recheck($log, $this->now());
        $this->assertSame('new_customer_message', $codes[0]);

        // บอทพิมพ์ใหม่ (anchor เดิม)
        $seed = $this->seedBase();
        $log = LeadRecoveryLog::query()->findOrFail($this->addLog($seed['room'], $seed['anchorId'], $seed['anchorId'], 'candidate'));
        $this->addMessage($seed['room']->id, 'bot', '2026-11-16 09:59:00', 'ตอบกลับอัตโนมัติ');
        $codes[] = FollowUpEligibility::recheck($log, $this->now());
        $this->assertSame('new_bot_message', $codes[1]);

        // ออเดอร์เกิดใหม่หลังข้อความลูกค้าสุดท้าย
        $seed = $this->seedBase();
        $log = LeadRecoveryLog::query()->findOrFail($this->addLog($seed['room'], $seed['anchorId'], $seed['anchorId'], 'candidate'));
        $this->addOrder($seed['bot']->id, $seed['room']->id, '2026-11-16 09:30:00');
        $codes[] = FollowUpEligibility::recheck($log, $this->now());
        $this->assertSame('order_created', $codes[2]);

        // สลิปผ่านหลังข้อความลูกค้าสุดท้าย
        $seed = $this->seedBase();
        $log = LeadRecoveryLog::query()->findOrFail($this->addLog($seed['room'], $seed['anchorId'], $seed['anchorId'], 'candidate'));
        $this->addSlip($seed['bot']->id, $seed['room']->id, 'passed', '2026-11-16 09:30:00');
        $codes[] = FollowUpEligibility::recheck($log, $this->now());
        $this->assertSame('passed_slip', $codes[3]);

        // สลับ handover
        $seed = $this->seedBase();
        $log = LeadRecoveryLog::query()->findOrFail($this->addLog($seed['room'], $seed['anchorId'], $seed['anchorId'], 'candidate'));
        $seed['room']->update(['is_handover' => true]);
        $codes[] = FollowUpEligibility::recheck($log, $this->now());
        $this->assertSame('handover_switched_on', $codes[4]);

        // ปิดสวิตช์บอท
        $seed = $this->seedBase();
        $log = LeadRecoveryLog::query()->findOrFail($this->addLog($seed['room'], $seed['anchorId'], $seed['anchorId'], 'candidate'));
        DB::table('bot_settings')->where('bot_id', $seed['bot']->id)->update(['follow_up_enabled' => false]);
        $codes[] = FollowUpEligibility::recheck($log, $this->now());
        $this->assertSame('bot_switched_off', $codes[5]);

        // นอกชั่วโมงส่ง
        $seed = $this->seedBase();
        $log = LeadRecoveryLog::query()->findOrFail($this->addLog($seed['room'], $seed['anchorId'], $seed['anchorId'], 'candidate'));
        $codes[] = FollowUpEligibility::recheck($log, $this->at('2026-11-16 22:30:00'));
        $this->assertSame('outside_hours', $codes[6]);

        $this->assertCount(7, array_unique($codes), 'แต่ละเหตุผลต้องมีโค้ดต่างกัน: '.json_encode($codes));
    }
}
