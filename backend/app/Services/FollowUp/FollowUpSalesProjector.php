<?php

namespace App\Services\FollowUp;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\FollowUpSalesSignal;
use App\Services\Payment\PaymentMessageDetector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Projector รายห้องของ read model "follow_up_sales_signals" — ไร้ข้อความในตาราง (D2)
 *
 * การ์ด B2b (plan line 137): เติมแถวของห้องจาก messages/orders "หลังขอบเขตการขาย"
 * เก็บ id + เวลา + ตัวนับ + boolean เท่านั้น ห้ามเก็บข้อความ
 *
 * ขอบเขตการขาย (span): ออเดอร์ล่าสุดของลูกค้านี้ (บอทเดียวกัน + LINE + external_customer_id
 * ข้ามทุกห้อง) ข้อความลูกค้านับเฉพาะ "หลัง" ออเดอร์นั้น; ไม่เคยซื้อ = นับ 30 วันล่าสุด
 * (คำนวณใหม่ทุก scan ไม่ cache ตาย — plan line 148)
 */
class FollowUpSalesProjector
{
    public const PROJECTION_VERSION = 1;

    /** ไม่เคยซื้อ = span คือ 30 วันล่าสุด (design "Step 1", plan line 148) */
    private const NEVER_BOUGHT_WINDOW_DAYS = 30;

    public static function refresh(Conversation $conversation, CarbonImmutable $now): FollowUpSalesSignal
    {
        // เวลากรุงเทพฯ ทุกจุด (คอลัมน์ตารางไม่มีโซน) — โซนไหนที่ caller ส่งมาก็เท่ากัน
        $now = $now->tz(FollowUpCapabilities::TIMEZONE)->startOfSecond();
        $roomId = $conversation->id;

        $latestOrder = self::latestCustomerOrder($conversation);
        $hasOrders = $latestOrder !== null;
        $boundary = $hasOrders
            ? CarbonImmutable::parse($latestOrder->created_at, FollowUpCapabilities::TIMEZONE)->startOfSecond()
            : $now->subDays(self::NEVER_BOUGHT_WINDOW_DAYS);

        // หลังออเดอร์ = "หลังกว่า" เข้มงวด; กรอบ 30 วัน = "ตั้งแต่" (>=) — plan line 148
        $spanOperator = $hasOrders ? '>' : '>=';

        $spanQuery = DB::table('messages')
            ->where('conversation_id', $roomId)
            ->where('created_at', $spanOperator, $boundary->format('Y-m-d H:i:s'));

        $customerMessageCount = (clone $spanQuery)->where('sender', 'user')->count();

        [$paymentStep, $transferSummarySent, $sensitiveAccountData] = self::scanSpan(
            (clone $spanQuery)->select('sender', 'content')->orderBy('id')->get()
        );

        $lastMessage = DB::table('messages')->where('conversation_id', $roomId)
            ->orderByDesc('id')->first(['id', 'created_at']);
        $lastUserMessage = DB::table('messages')->where('conversation_id', $roomId)
            ->where('sender', 'user')->orderByDesc('id')->first(['id', 'created_at']);

        $row = [
            'last_message_id' => $lastMessage->id ?? null,
            'last_message_at' => $lastMessage->created_at ?? null,
            'last_user_message_id' => $lastUserMessage->id ?? null,
            'last_user_at' => $lastUserMessage->created_at ?? null,
            'latest_order_id' => $latestOrder->id ?? null,
            'sales_started_at' => $boundary->format('Y-m-d H:i:s'),
            'customer_message_count' => $customerMessageCount,
            'payment_step' => $paymentStep,
            'transfer_summary_sent' => $transferSummarySent,
            'sensitive_account_data' => $sensitiveAccountData,
            'projected_at' => $now->format('Y-m-d H:i:s'),
            'projection_version' => self::PROJECTION_VERSION,
        ];

        // upsert ผ่าน query builder เท่านั้น — ตารางใช้ PK = conversation_id (ไม่มีคอลัมน์ id)
        // updateOrCreate ของโมเดลจะยิง UPDATE ... WHERE id = null แล้วอัปเดตไม่เข้าแถวใด
        $exists = DB::table('follow_up_sales_signals')->where('conversation_id', $roomId)->exists();
        if ($exists) {
            DB::table('follow_up_sales_signals')->where('conversation_id', $roomId)->update($row);
        } else {
            DB::table('follow_up_sales_signals')->insert($row + ['conversation_id' => $roomId]);
        }

        return FollowUpSalesSignal::query()->where('conversation_id', $roomId)->first();
    }

    /** scan ทั้งบอท (ได้บอทในลิสต์เท่านั้น) แบบ keyset chunks — ห้ามกินหน่วยรายใหญ่ */
    public static function refreshBot(Bot $bot, CarbonImmutable $now): void
    {
        $now = $now->tz(FollowUpCapabilities::TIMEZONE)->startOfSecond();

        if (! in_array((int) $bot->id, array_map(intval(...), (array) config('customer_follow_up.bot_ids', [])), true)) {
            return;
        }

        Conversation::query()
            ->where('bot_id', $bot->id)
            ->orderBy('id')
            ->chunkById(200, function ($rooms) use ($now): void {
                foreach ($rooms as $room) {
                    self::refresh($room, $now);
                }
            });
    }

    /**
     * ออเดอร์ล่าสุดของ "ลูกค้านี้" บนบอทนี้ (ข้ามห้อง) — นิยาม MAX(id)
     * ให้ตรงกับ predicate ของ gate เป๊ะ (NULL-safe เทียบกับ signals.latest_order_id)
     */
    private static function latestCustomerOrder(Conversation $conversation): ?object
    {
        return DB::table('orders')
            ->join('conversations as oc', 'oc.id', '=', 'orders.conversation_id')
            ->where('orders.bot_id', $conversation->bot_id)
            ->where('oc.channel_type', 'line')
            ->where('oc.external_customer_id', $conversation->external_customer_id)
            ->orderByDesc('orders.id')
            ->first(['orders.id', 'orders.created_at']);
    }

    /**
     * อ่านข้อความใน span เพื่อคิดสามธง — อ่าน text แบบ local เท่านั้น ห้ามออก network
     * payment_step/transfer_summary_sent: เฉพาะข้อความ "บอท" ใน span (plan line 149);
     * sensitive_account_data: ทุก sender ใน span (plan line 157)
     */
    private static function scanSpan($spanMessages): array
    {
        $payment = new PaymentMessageDetector;

        $paymentStep = false;
        $transferSummarySent = false;
        $sensitive = false;

        foreach ($spanMessages as $message) {
            if ($message->sender === 'bot') {
                $isPayment = $payment->isPaymentMessage($message->content);
                $paymentStep = $paymentStep
                    || $isPayment
                    || $payment->isSupportDelayMessage($message->content)
                    || ($payment->isConfirmMessage($message->content) && $payment->hasConfirmIntent($message->content));
                $transferSummarySent = $transferSummarySent || $isPayment;
            }

            $sensitive = $sensitive || FollowUpAccountDataDetector::containsAccountData($message->content);
        }

        return [$paymentStep, $transferSummarySent, $sensitive];
    }
}
