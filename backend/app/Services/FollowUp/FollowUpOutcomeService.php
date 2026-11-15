<?php

namespace App\Services\FollowUp;

use App\Models\Conversation;
use App\Models\LeadRecoveryLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ตัวนับผลหลังส่งข้อความตามลูกค้า (การ์ด B4d — D56-D59)
 *
 * - ตอบกลับภายใน 72 ชม.: วัดจาก created_at ของข้อความลูกค้าเทียบ sent_at ของแถว
 *   ไม่ใช่จากเวลาที่มาเรียก (probe 2 ของ Lead)
 * - ออเดอร์ภายใน 7 วัน: เฉพาะ status completed วัดจาก created_at ของออเดอร์
 * - หนึ่งเหตุการณ์ (ข้อความ/ออเดอร์) นับให้แถวเดียว: แถว sent ล่าสุดก่อนเหตุการณ์ (D56) —
 *   cap คือ sent_at ของ "แถวถัดไป" ของลูกค้าเดียวกัน
 * - ขอบเขต "ลูกค้า": conversations เดียวกันทั้ง bot_id, channel_type, external_customer_id
 *   (ไม่กรอง deleted_at เหมือน gate); external_customer_id ว่าง = ห้องตัวเองห้องเดียว
 * - outcome เป็น null จนกว่าจะตัดสิน แล้วไม่ถูกเขียนทับ (D58): true เมื่อเจอ ตัดสินไม่ได้ = คง null,
 *   false เขียนได้เฉพาะ reconcile เมื่อพ้นหน้าต่าง + grace 600 วินาที
 * - ทุกการเขียนเป็น UPDATE เดียวที่มี where <คอลัมน์ผล> is null กันการเขียนทับทุกทาง (D58)
 * - ทุกเวลาเป็น Bangkok Y-m-d H:i:s เทียบเป็นสตริง ไม่มีการแปลง UTC
 */
class FollowUpOutcomeService
{
    /** หน้าต่างการตอบกลับ (ชั่วโมง) นับจาก sent_at ของแถว */
    public const RESPONSE_WINDOW_HOURS = 72;

    /** หน้าต่างการซื้อ (วัน) นับจาก sent_at ของแถว */
    public const ORDER_WINDOW_DAYS = 7;

    /** เผื่อเวลาหลังหน้าต่างปิด ก่อน reconcile บันทึก false (วินาที) */
    public const CLOSE_GRACE_SECONDS = 600;

    /** จำนวนแถวต่อรอบของ reconcile */
    private const CHUNK_SIZE = 200;

    private const TIMEZONE = FollowUpCapabilities::TIMEZONE;

    /**
     * ตัดสินทั้งสองผลของทุกแถว sent ที่ยังมี replied_within_72h หรือ ordered_within_7d เป็น null
     * (ยังไม่มีตัวจดที่เรียก — job/ตารางเวลาเป็นการ์ดถัดไป)
     */
    public function reconcile(CarbonImmutable $now): void
    {
        $now = $this->normalizeNow($now);

        DB::table('lead_recovery_logs')
            ->where('delivery_status', LeadRecoveryLog::DELIVERY_STATUS_SENT)
            ->whereNotNull('sent_at')
            ->where(function ($query): void {
                $query->whereNull('replied_within_72h')->orWhereNull('ordered_within_7d');
            })
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($now): void {
                foreach ($rows as $row) {
                    $conversation = DB::table('conversations')
                        ->where('id', (int) $row->conversation_id)
                        ->first(['id', 'bot_id', 'channel_type', 'external_customer_id']);

                    if ($conversation === null) {
                        continue;
                    }

                    $capAt = $this->nextRowSentAt($row, $conversation);
                    $this->decideReply($row, $conversation, $capAt, $now, true);
                    $this->decideOrder($row, $conversation, $capAt, $now);
                }
            }, column: 'id');
    }

    /**
     * ทาง customer-message path (D57): ตัดสิน "เฉพาะ" ผลตอบกลับ true ของแถว sent ที่ยังเปิด
     * (replied_within_72h null) ของลูกค้าห้องนี้ — ไม่มีสิทธิ์เขียน false และไม่แตะคอลัมน์ออเดอร์
     * เมื่อไม่มีแถวที่ยังเปิด = ONE select แล้วจบ ไม่เขียนอะไรเลย
     */
    public function recordReply(Conversation $conversation, CarbonImmutable $now): void
    {
        $now = $this->normalizeNow($now);

        foreach ($this->openReplyRows($conversation) as $row) {
            $conversationOfRow = (object) [
                'id' => $conversation->id,
                'bot_id' => $conversation->bot_id,
                'channel_type' => $conversation->channel_type,
                'external_customer_id' => $conversation->external_customer_id,
            ];

            $this->decideReply(
                $row,
                $conversationOfRow,
                $this->nextRowSentAt($row, $conversationOfRow),
                $now,
                false,
            );
        }
    }

    // ------------------------------------------------------------------
    // การตัดสิน
    // ------------------------------------------------------------------

    private function decideReply(object $row, object $conversation, ?string $capAt, CarbonImmutable $now, bool $mayCloseFalse): void
    {
        $reply = $this->earliestReply($row, $conversation, $capAt);

        if ($reply !== null) {
            $this->writeReplyTrue($row, (string) $reply->created_at, $this->formatNow($now));

            return;
        }

        if (! $mayCloseFalse) {
            return; // recordReply ไม่มีสิทธิ์บันทึก false — ผลยังค้าง null
        }

        $cutoff = $this->windowEnd($row->sent_at, self::RESPONSE_WINDOW_HOURS)
            ->addSeconds(self::CLOSE_GRACE_SECONDS)
            ->format('Y-m-d H:i:s');

        if ($this->formatNow($now) < $cutoff) {
            return; // ยังอยู่ในหน้าต่าง + grace — คง null (ค้างรอ)
        }

        $this->writeReplyFalse($row, $this->formatNow($now));
    }

    private function decideOrder(object $row, object $conversation, ?string $capAt, CarbonImmutable $now): void
    {
        $order = $this->earliestOrder($row, $conversation, $capAt);

        if ($order !== null) {
            $this->writeOrderTrue($row, (int) $order->id, (string) $order->created_at, $this->formatNow($now));

            return;
        }

        $cutoff = $this->windowEnd($row->sent_at, self::ORDER_WINDOW_DAYS * 24)
            ->addSeconds(self::CLOSE_GRACE_SECONDS)
            ->format('Y-m-d H:i:s');

        if ($this->formatNow($now) < $cutoff) {
            return; // ค้างรอ
        }

        $this->writeOrderFalse($row, $this->formatNow($now));
    }

    // ------------------------------------------------------------------
    // คิวรีหาเหตุการณ์
    // ------------------------------------------------------------------

    /** ข้อความลูกค้าแรกสุดในหน้าต่าง 72 ชม. ของแถว (ปลายรวม), โดน cap ของแถวถัดไปถ้ามี */
    private function earliestReply(object $row, object $conversation, ?string $capAt): ?object
    {
        $windowEnd = $this->windowEnd($row->sent_at, self::RESPONSE_WINDOW_HOURS)->format('Y-m-d H:i:s');

        return DB::table('messages')
            ->whereIn('conversation_id', $this->customerConversationIds($conversation))
            ->where('sender', 'user')
            ->where('created_at', '>', (string) $row->sent_at)
            ->where('created_at', '<=', $windowEnd)
            ->when($capAt !== null, fn ($query) => $query->where('created_at', '<=', $capAt))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first(['id', 'created_at']);
    }

    /** ออเดอร์ completed แรกสุดในหน้าต่าง 7 วัน ของลูกค้าเดียวกัน บอทเดียวกัน (ปลายรวม), cap เหมือนกัน */
    private function earliestOrder(object $row, object $conversation, ?string $capAt): ?object
    {
        $windowEnd = $this->windowEnd($row->sent_at, self::ORDER_WINDOW_DAYS * 24)->format('Y-m-d H:i:s');

        return DB::table('orders')
            ->join('conversations as oc', 'oc.id', '=', 'orders.conversation_id')
            ->whereIn('oc.id', $this->customerConversationIds($conversation))
            ->where('orders.bot_id', (int) $row->bot_id)
            ->where('orders.status', 'completed')
            ->where('orders.created_at', '>', (string) $row->sent_at)
            ->where('orders.created_at', '<=', $windowEnd)
            ->when($capAt !== null, fn ($query) => $query->where('orders.created_at', '<=', $capAt))
            ->orderBy('orders.created_at')
            ->orderBy('orders.id')
            ->first(['orders.id', 'orders.created_at']);
    }

    /**
     * sent_at ของ "แถวถัดไป" ของลูกค้าคนเดียวกัน — แถว sent ที่ (sent_at, id) จัดเรียงแล้ว
     * อยู่หลังแถวนี้ทันที (D56: เหตุการณ์ที่เกิดหลังแถวถัดไปยิงไปแล้วเป็นของแถวถัดไป)
     */
    private function nextRowSentAt(object $row, object $conversation): ?string
    {
        $query = DB::table('lead_recovery_logs')
            ->where('delivery_status', LeadRecoveryLog::DELIVERY_STATUS_SENT)
            ->whereNotNull('sent_at')
            ->where(function ($query) use ($row): void {
                $query->where('sent_at', '>', (string) $row->sent_at)
                    ->orWhere(function ($query) use ($row): void {
                        $query->where('sent_at', '=', (string) $row->sent_at)
                            ->where('lead_recovery_logs.id', '>', (int) $row->id);
                    });
            })
            ->orderBy('sent_at')
            ->orderBy('lead_recovery_logs.id');

        if (blank($conversation->external_customer_id)) {
            $query->where('lead_recovery_logs.conversation_id', (int) $conversation->id);
        } else {
            $query->join('conversations as c', 'c.id', '=', 'lead_recovery_logs.conversation_id')
                ->where('lead_recovery_logs.bot_id', (int) $conversation->bot_id)
                ->where('c.channel_type', (string) $conversation->channel_type)
                ->where('c.external_customer_id', (string) $conversation->external_customer_id);
        }

        return $query->value('sent_at');
    }

    /** id ของทุกห้องที่เป็น "ลูกค้าเดียวกัน" — ไม่มี id ตัวตน = ห้องตัวเองห้องเดียว (D56) */
    private function customerConversationIds(object $conversation): array
    {
        if (blank($conversation->external_customer_id)) {
            return [(int) $conversation->id];
        }

        return DB::table('conversations')
            ->where('bot_id', (int) $conversation->bot_id)
            ->where('channel_type', (string) $conversation->channel_type)
            ->where('external_customer_id', (string) $conversation->external_customer_id)
            ->pluck('id')
            ->all();
    }

    /** แถว sent ที่ยังเปิด (replied null) ของลูกค้าห้องนี้ — ONE select เมื่อไม่มีใครเปิด (D57) */
    private function openReplyRows(Conversation $conversation): array
    {
        $query = DB::table('lead_recovery_logs')
            ->where('delivery_status', LeadRecoveryLog::DELIVERY_STATUS_SENT)
            ->whereNotNull('sent_at')
            ->whereNull('replied_within_72h');

        if (blank($conversation->external_customer_id)) {
            $query->where('lead_recovery_logs.conversation_id', (int) $conversation->id);
        } else {
            $query->join('conversations as c', 'c.id', '=', 'lead_recovery_logs.conversation_id')
                ->where('lead_recovery_logs.bot_id', (int) $conversation->bot_id)
                ->where('c.channel_type', (string) $conversation->channel_type)
                ->where('c.external_customer_id', (string) $conversation->external_customer_id)
                ->select('lead_recovery_logs.*');
        }

        return $query->orderByDesc('sent_at')->orderByDesc('lead_recovery_logs.id')->get()->all();
    }

    // ------------------------------------------------------------------
    // การเขียน — UPDATE เดียวต่อผล กันเขียนทับด้วย where <คอลัมน์ผล> is null (D58)
    // ------------------------------------------------------------------

    private function writeReplyTrue(object $row, string $respondedAt, string $nowStr): void
    {
        $written = DB::table('lead_recovery_logs')
            ->where('id', (int) $row->id)
            ->whereNull('replied_within_72h')
            ->update([
                'replied_within_72h' => true,
                'customer_responded' => true,
                'responded_at' => $respondedAt,
                'response_window_closed_at' => $nowStr,
                'updated_at' => $nowStr,
            ]) === 1;

        if ($written) {
            Log::info('Customer follow-up outcome', [
                'log_id' => (int) $row->id,
                'conversation_id' => (int) $row->conversation_id,
                'outcome' => 'reply',
                'value' => 'true',
            ]);
        }
    }

    private function writeReplyFalse(object $row, string $nowStr): void
    {
        $written = DB::table('lead_recovery_logs')
            ->where('id', (int) $row->id)
            ->whereNull('replied_within_72h')
            ->update([
                'replied_within_72h' => false,
                'response_window_closed_at' => $nowStr,
                'updated_at' => $nowStr,
            ]) === 1;

        if ($written) {
            Log::info('Customer follow-up outcome', [
                'log_id' => (int) $row->id,
                'conversation_id' => (int) $row->conversation_id,
                'outcome' => 'reply',
                'value' => 'false',
            ]);
        }
    }

    private function writeOrderTrue(object $row, int $orderId, string $orderedAt, string $nowStr): void
    {
        $written = DB::table('lead_recovery_logs')
            ->where('id', (int) $row->id)
            ->whereNull('ordered_within_7d')
            ->update([
                'ordered_within_7d' => true,
                'order_id' => $orderId,
                'ordered_at' => $orderedAt,
                'order_window_closed_at' => $nowStr,
                'updated_at' => $nowStr,
            ]) === 1;

        if ($written) {
            Log::info('Customer follow-up outcome', [
                'log_id' => (int) $row->id,
                'conversation_id' => (int) $row->conversation_id,
                'outcome' => 'order',
                'value' => 'true',
                'order_id' => $orderId,
            ]);
        }
    }

    private function writeOrderFalse(object $row, string $nowStr): void
    {
        $written = DB::table('lead_recovery_logs')
            ->where('id', (int) $row->id)
            ->whereNull('ordered_within_7d')
            ->update([
                'ordered_within_7d' => false,
                'order_window_closed_at' => $nowStr,
                'updated_at' => $nowStr,
            ]) === 1;

        if ($written) {
            Log::info('Customer follow-up outcome', [
                'log_id' => (int) $row->id,
                'conversation_id' => (int) $row->conversation_id,
                'outcome' => 'order',
                'value' => 'false',
            ]);
        }
    }

    // ------------------------------------------------------------------
    // เวลา — ทุกอย่างเป็น Bangkok Y-m-d H:i:s ไม่แปลง UTC
    // ------------------------------------------------------------------

    private function normalizeNow(CarbonImmutable $now): CarbonImmutable
    {
        return $now->tz(self::TIMEZONE)->startOfSecond();
    }

    private function formatNow(CarbonImmutable $now): string
    {
        return $now->format('Y-m-d H:i:s');
    }

    private function windowEnd(string $sentAt, int $hours): CarbonImmutable
    {
        return CarbonImmutable::parse($sentAt, self::TIMEZONE)->addHours($hours);
    }
}
