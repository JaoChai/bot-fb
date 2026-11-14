<?php

namespace App\Services;

use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\OutageMessageText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ส่งข้อความ "ขอให้ลูกค้าพิมพ์ใหม่" ให้ห้องที่ค้างจากตอนบอทล่ม (การ์ด t_44675510)
 *
 * ห้องที่ "รอ" ครบทุกข้อ: ข้อความล่าสุดของแชท (messages.id สูงสุด) เป็นแถวบอทติด tag
 * metadata.model_error = "auth" และยังไม่มี recovery_notice, แถวอายุ ≤ 24 ชม.,
 * และ is_handover = false (กฎ "ข้อความล่าสุด" ครอบเอง: แอดมินตอบ / บอทตอบปกติ /
 * ลูกค้าพิมพ์ใหม่ / แจ้งไปแล้ว = ห้องนั้นไม่รอแล้วทั้งหมด)
 *
 * ลำดับต่อรอบ: มีห้องรอ → อยู่ช่วง 08:00-22:00 (Asia/Bangkok) → probe
 * GET {base_url}/key = 200 เท่านั้น → ต่อห้อง: จอง recovery_notice = "sending" แบบ atomic
 * ส่งเฉพาะเมื่อ UPDATE จองได้ 1 แถว; แพ้จอง = ข้าม ไม่ส่ง/เขียนแถว/นับยอด
 * สำเร็จ: เขียนแถวแจ้งลูกค้า (metadata.outage_notice = true) + "sent",
 * ล้ม: "failed" และไม่มีแถว — ตายหลังจองก็ไม่ retry; รอบซ้อนกันไม่ส่งแถวเดิมซ้ำ
 * ปิดท้าย: "Back" แจ้งเจ้าของต่อบอท 1 ข้อความ รวมยอด sent/failed ของบอทนั้น
 */
class OutageRecoveryService
{
    /** ช่วงเวลาที่อนุญาตส่ง (ชั่วโมง Asia/Bangkok) — 08:00:00 ถึงก่อน 22:00:00 */
    private const SENDING_START_HOUR = 8;

    private const SENDING_END_HOUR = 22;

    /** แถวติด tag อายุเกินนี้ = ห้องค้างนานเกินไป ปล่อยผ่าน (24 ชม.พอดียังนับ) */
    private const MAX_AGE_HOURS = 24;

    /** ข้อความลูกค้าไม่มีตัวอักษรไทยตัวไหน = แถบภาษาอังกฤษท้ายข้อความ */
    private const THAI_CHAR_REGEX = '/[\x{0E00}-\x{0E7F}]/u';

    public const CUSTOMER_TEXT_THAI = OutageMessageText::RECOVERY_THAI;

    public const CUSTOMER_TEXT_ENGLISH = OutageMessageText::RECOVERY_ENGLISH;

    public function __construct(
        private readonly LeadRecoveryService $leadRecovery,
        private readonly OutageNoticeService $outageNotice,
        private readonly OpenRouterService $openRouter,
    ) {}

    public function run(): void
    {
        $waiting = $this->waitingRooms();

        if ($waiting->isEmpty()) {
            return;
        }

        if (! $this->withinSendingHours()) {
            return;
        }

        if (! $this->openRouter->keyWorks()) {
            // กุญแจยังถูกปฏิเสธ = บอทยังล่ม — เงียบไว้ รอรอบหน้า (probe ยิงเมื่อมีห้องรอเท่านั้น)
            Log::info('Outage recovery: key still rejected, skipping this run', [
                'waiting_rooms' => $waiting->count(),
            ]);

            return;
        }

        $this->sendNotices($waiting);
    }

    /**
     * แถวติด tag ที่เป็น "ข้อความล่าสุด" ของห้องที่ไม่อยู่ใน handover — คืนแถวเรียงตาม id
     *
     * @return Collection<int, Message>
     */
    private function waitingRooms(): Collection
    {
        $candidates = Message::query()
            ->where('sender', 'bot')
            ->where('metadata->model_error', 'auth')
            ->where('created_at', '>=', now()->subHours(self::MAX_AGE_HOURS))
            ->orderBy('id')
            ->with('conversation')
            ->get()
            // มี recovery_notice แล้ว (sending/sent/failed) = จบตอนนี้แล้ว ห้ามแตะซ้ำ
            ->filter(fn (Message $message) => ! array_key_exists('recovery_notice', $message->metadata ?? []))
            ->values();

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $conversationIds = $candidates->pluck('conversation_id')->unique()->all();

        // ข้อความล่าสุดต่อห้อง — อ่านจากตาราง messages ตรงนี้ ไม่ใช่ conversations.last_message_id
        $newestIdByConversation = Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->selectRaw('conversation_id, max(id) as latest_id')
            ->groupBy('conversation_id')
            ->pluck('latest_id', 'conversation_id');

        $notHandoverIds = Conversation::query()
            ->whereIn('id', $conversationIds)
            ->where('is_handover', false)
            ->pluck('id');

        return $candidates->filter(function (Message $message) use ($newestIdByConversation, $notHandoverIds) {
            $newestId = (int) $newestIdByConversation->get($message->conversation_id);

            return $newestId === (int) $message->id && $notHandoverIds->contains($message->conversation_id);
        })->values();
    }

    private function withinSendingHours(): bool
    {
        $hour = now()->hour;

        return $hour >= self::SENDING_START_HOUR && $hour < self::SENDING_END_HOUR;
    }

    /**
     * @param  Collection<int, Message>  $waiting
     */
    private function sendNotices(Collection $waiting): void
    {
        $countsByBot = [];

        foreach ($waiting as $tagged) {
            $conversation = $tagged->conversation;
            $bot = $conversation->bot;

            // อ่านการตั้งค่า "สด" ต่อห้อง ก่อนจอง (การ์ด outage-settings B2) —
            // อ่านล้ม = ไม่จอง ไม่ส่ง ไม่นับ ห้องยังรอรอบหน้า (card addition #2)
            try {
                $settings = BotSetting::query()->where('bot_id', $bot->id)->first();
            } catch (\Throwable $e) {
                Log::warning('Outage recovery: settings lookup failed — room stays unclaimed', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            // สวิตช์ปิด (R6): ปิดห้องแบบ terminal ด้วย "disabled" — ไม่ push, ไม่เขียนแถวแจ้งลูกค้า
            if (($settings?->outage_recovery_enabled ?? true) !== true) {
                if ($this->claimDisabled($tagged)) {
                    $countsByBot[$bot->id] ??= ['bot' => $bot, 'sent' => 0, 'failed' => 0, 'disabled' => 0];
                    $countsByBot[$bot->id]['disabled']++;
                }

                continue;
            }

            // จองแบบ atomic ก่อนส่ง — ผู้ชนะเท่านั้นส่งได้; ตายหลังจองก็ไม่ retry
            if (! $this->claim($tagged)) {
                continue;
            }

            $text = $this->noticeText($conversation, $tagged, $settings);
            // F18: ข้อความแจ้งลูกค้าเป็นข้อความที่เจ้าของแก้เองได้ → plain text
            $sent = $this->leadRecovery->sendStaticFollowUp($conversation, $text, true);

            if ($sent) {
                // แถวแจ้งลูกค้า: content = ข้อความที่ถูก push จริงทุกตัวอักษร
                $conversation->messages()->create([
                    'sender' => 'bot',
                    'content' => $text,
                    'type' => 'text',
                    'metadata' => ['outage_notice' => true],
                ]);
                $this->mark($tagged, 'sent');
            } else {
                $this->mark($tagged, 'failed');
            }

            $countsByBot[$bot->id] ??= ['bot' => $bot, 'sent' => 0, 'failed' => 0, 'disabled' => 0];
            $countsByBot[$bot->id][$sent ? 'sent' : 'failed']++;
        }

        // "Back" — ต่อบอท 1 ข้อความ เฉพาะรอบที่พยายามส่งอย่างน้อย 1 ห้อง
        foreach ($countsByBot as $entry) {
            $this->outageNotice->alertOwnerRecovered($entry['bot'], $entry['sent'], $entry['failed'], $entry['disabled']);
        }
    }

    private function claim(Message $tagged): bool
    {
        return $this->claimWith($tagged, 'sending');
    }

    /**
     * ปิดห้องแบบ terminal (R6): recovery_notice = "disabled" แบบ atomic เดียวกับ
     * claim ปกติ — ผู้ชนะเท่านั้นถูกนับยอด disabled ของ "Back"
     */
    private function claimDisabled(Message $tagged): bool
    {
        return $this->claimWith($tagged, 'disabled');
    }

    /**
     * จอง status ใดๆ ของ recovery_notice แบบ atomic: SET ทั้งค่า JSON เพื่อรองรับ
     * PostgreSQL metadata ที่เป็น json (ไม่ใช่ jsonb)
     */
    private function claimWith(Message $tagged, string $status): bool
    {
        $metadata = $tagged->metadata ?? [];
        $metadata['recovery_notice'] = $status;
        $tagged->forceFill(['metadata' => $metadata]);

        return $tagged->newQuery()
            ->whereKey($tagged->getKey())
            ->whereJsonDoesntContainKey('metadata->recovery_notice')
            ->update(['metadata' => $tagged->getAttributes()['metadata']]) === 1;
    }

    private function mark(Message $tagged, string $status): void
    {
        $metadata = $tagged->metadata ?? [];
        $metadata['recovery_notice'] = $status;
        $tagged->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * ข้อความลูกค้า: ไทยเสมอ + แถบอังกฤษต่อท้ายเมื่อข้อความ (type=text) ล่าสุดของ
     * ลูกค้าก่อนแถวติด tag ไม่มีตัวอักษรไทยเลย — ข้อความจริงอ่านจาก $settings
     * ที่อ่านสดก่อนจอง (ค่าว่าง/null = ใช้ค่าเริ่มต้นของโค้ด)
     */
    private function noticeText(Conversation $conversation, Message $tagged, ?BotSetting $settings): string
    {
        $lastCustomerText = $conversation->messages()
            ->where('sender', 'user')
            ->where('type', 'text')
            ->where('id', '<', $tagged->id)
            ->orderByDesc('id')
            ->value('content');

        $needsEnglish = is_string($lastCustomerText)
            && $lastCustomerText !== ''
            && preg_match(self::THAI_CHAR_REGEX, $lastCustomerText) !== 1;

        if (! $needsEnglish) {
            return OutageMessageText::resolve($settings?->outage_recovery_message_th, OutageMessageText::RECOVERY_THAI);
        }

        return OutageMessageText::resolve($settings?->outage_recovery_message_th, OutageMessageText::RECOVERY_THAI)
            ."\n\n"
            .OutageMessageText::resolve($settings?->outage_recovery_message_en, OutageMessageText::RECOVERY_ENGLISH);
    }
}
