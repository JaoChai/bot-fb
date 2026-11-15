<?php

namespace App\Services\FollowUp;

use App\Models\FlowPlugin;
use App\Models\LeadRecoveryLog;
use App\Services\Payment\TelegramAlertBotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ปุ่มส่ง/ข้ามบนการ์ดตามลูกค้าใน Telegram — การ์ด B6b (plan §3.6, คำตัดสิน D75-D80)
 *
 * บัตรละหนึ่งบทสนทนา: กด fs = อนุมัติ, fk = ข้าม — ปุ่มของ B6b ตอบคนกดแล้วเขียนแถว
 * คู่ log + approval "หนึ่งธุรกรรม" ให้ผู้ส่ง (B6d) หยิบไปส่งต่อเอง การ์ดนี้ไม่ส่งหาลูกค้าเอง
 *
 * กติกาอัตลักษณ์ของตัวเอง (D77): ห้องตรง plugin, คนอยู่ใน allowlist ที่ "ชัดเจน" (ต่างจาก
 * isAuthorizedUser เดิมที่ยอมรับทุกคนเมื่อลิสต์ว่าง), บัตรผูกกับ plugin+ห้อง+ข้อความ+บอท,
 * revision+nonce ยังเป็นรุ่นปัจจุบัน — คนนอกต้องได้คำตอบเดียวกันไม่ว่าบัตรจะมีจริงหรือไม่
 *
 * ผลคืนของ callback(): approved|skipped|refused|ignored — route ตอบ {ok:true} เสมอ
 * ไม่ log raw update / token / chat id / nonce / ข้อความ — log เดียวต่อการเรียก
 */
class FollowUpApprovalService
{
    /** fs|<approval bigint>|<revision uint>|<nonce16hex> — เต็มรูปเท่านั้น (§3.6); /D = ห้ามต่อท้ายด้วย \n */
    private const DATA_PATTERN = '/^(fs|fk|fe|fc)\|([1-9][0-9]{0,18})\|([1-9][0-9]{0,9})\|([0-9a-f]{16})$/D';

    private const MAX_ID = '9223372036854775807';

    private const MAX_REVISION = '2147483647';

    /** คำตอบตรงตัวกับ b6b-strings.json (sha256 9e1f610e…) — ห้ามแก้ที่นี่ลอยๆ */
    private const ANSWERS = [
        'send_ok' => 'รับคำสั่งแล้ว กำลังส่งให้ลูกค้า',
        'send_ok_flagged' => 'รับคำสั่งแล้ว กำลังส่งให้ลูกค้า (ข้อความนี้มีคำเตือน)',
        'skip_ok' => 'ข้ามแล้ว ไม่ส่งข้อความนี้',
        'no_permission' => 'ไม่มีสิทธิ์กดปุ่มนี้',
        'not_found' => 'ไม่พบรายการนี้ หรือการ์ดนี้ใช้ไม่ได้แล้ว',
        'stale_card' => 'การ์ดนี้เป็นรุ่นเก่า ใช้การ์ดล่าสุดแทน',
        'expired' => 'หมดอายุแล้ว ไม่ได้ส่ง',
        'not_ready' => 'ปุ่มนี้ยังไม่เปิดใช้',
        'skip_too_late' => 'กดส่งไปแล้ว ข้ามไม่ได้',
    ];

    private const ANSWERS_ALREADY = [
        'approved' => 'กดส่งไปแล้ว กำลังส่งให้ลูกค้า',
        'sending' => 'กดส่งไปแล้ว กำลังส่งให้ลูกค้า',
        'sent' => 'ส่งให้ลูกค้าไปแล้ว',
        'skipped' => 'ข้ามไปแล้ว ไม่ได้ส่ง',
        'expired' => 'หมดอายุแล้ว ไม่ได้ส่ง',
        'cancelled' => 'รายการนี้ถูกยกเลิกแล้ว ไม่ได้ส่ง',
        'editing' => 'กำลังแก้ข้อความอยู่',
    ];

    private const ANSWERS_GATE = [
        'outside_hours' => 'ตอนนี้นอกเวลาส่ง ({start}–{end}) ยังไม่ได้ส่ง กดใหม่ในเวลาได้',
        'bot_switched_off' => 'ระบบตามลูกค้าของบอทนี้ปิดอยู่ ยังไม่ได้ส่ง',
        'capabilities' => 'บอทนี้ยังตั้งค่าไม่ครบ ยังไม่ได้ส่ง',
        'room_not_active' => 'ห้องนี้ปิดไปแล้ว ไม่ได้ส่ง',
        'handover_switched_on' => 'แอดมินรับห้องนี้อยู่ ยังไม่ได้ส่ง',
        'new_customer_message' => 'ลูกค้าพิมพ์เข้ามาแล้ว ไม่ต้องตาม',
        'new_bot_message' => 'บอทตอบในห้องนี้ไปแล้ว ไม่ได้ส่ง',
        'order_created' => 'ลูกค้าสั่งซื้อแล้ว ไม่ต้องตาม',
        'passed_slip' => 'ลูกค้าสั่งซื้อแล้ว ไม่ต้องตาม',
        'other' => 'ตอนนี้ยังส่งไม่ได้ ลองใหม่ภายหลัง',
    ];

    /** คำตอบที่เด้งเป็น toast (show_alert false) — ที่เหลือเด้งกล่องเตือน */
    private const TOAST_KEYS = ['send_ok', 'send_ok_flagged', 'skip_ok'];

    public function __construct(
        private readonly TelegramAlertBotService $telegram,
        private readonly FollowUpTelegramService $followUpTelegram,
    ) {}

    /**
     * ทางเข้าจาก route ของการ์ด fs|fk|fe|fc — ตอบ callback และเขียนแถวเอง
     * คืน approved|skipped (เขียนสำเร็จ), refused (ตอบแล้วแต่เขียนไม่ได้), ignored (เงียบ)
     */
    public function callback(FlowPlugin $plugin, array $callback, int $updateId, ?CarbonImmutable $now = null): string
    {
        $now = ($now ?? CarbonImmutable::now(FollowUpCapabilities::TIMEZONE))
            ->tz(FollowUpCapabilities::TIMEZONE)
            ->startOfSecond();
        $nowStr = $now->format('Y-m-d H:i:s');
        $botId = (int) DB::table('flows')->where('id', $plugin->flow_id)->value('bot_id');

        // 1) รูปแบบข้อมูล — ไม่ตรง = เงียบ (ไม่ตอบ ไม่เขียน)
        $parsed = $this->parseData($callback);
        if ($parsed === null) {
            return $this->finish(null, null, $botId, '?', 'ignored', 'shape', null);
        }
        [$code, $approvalId, $revision, $nonce] = $parsed;

        // 2) ห้อง — การ์ดถูกกดในห้องอื่น = เงียบ; chat.id ต้องเป็นจำนวนเต็มหรือข้อความเท่านั้น (array อื่นๆ = เงียบ)
        $rawChatId = $callback['message']['chat']['id'] ?? null;
        if (! is_int($rawChatId) && ! is_string($rawChatId)) {
            return $this->finish(null, null, $botId, $code, 'ignored', 'room', null);
        }
        $chatId = (string) $rawChatId;
        if ($chatId === '' || $chatId !== (string) ($plugin->config['chat_id'] ?? '')) {
            return $this->finish(null, null, $botId, $code, 'ignored', 'room', null);
        }

        $token = (string) ($plugin->config['access_token'] ?? '');
        $cbId = (string) ($callback['id'] ?? '');

        // 3) คน — from.id ต้องเป็นจำนวนเต็ม > 0 และอยู่ใน allowlist ที่ชัดเจน (D77)
        $fromId = $callback['from']['id'] ?? null;
        $personAllowed = is_int($fromId) && $fromId > 0 && $this->personAllowed($plugin, $fromId);
        if (! $personAllowed) {
            $this->answer($token, $cbId, self::ANSWERS['no_permission']);

            return $this->finish(null, null, $botId, $code, 'refused', 'no_permission', is_int($fromId) ? $fromId : null);
        }
        $fromIdStr = (string) $fromId;

        // 4) แถว
        $approval = $this->approvalRow($approvalId);
        $log = $approval === null ? null : $this->logRow((int) $approval->lead_recovery_log_id);
        if ($approval === null || $log === null) {
            $this->answer($token, $cbId, self::ANSWERS['not_found']);

            return $this->finish(null, null, $botId, $code, 'refused', 'not_found', $fromId);
        }

        // 5) การผูก — บัตรต้องเป็นของ plugin นี้ ห้องนี้ บอทนี้
        if ((int) $approval->telegram_plugin_id !== (int) $plugin->id
            || (string) $approval->telegram_chat_id !== $chatId
            || (int) $log->bot_id !== $botId
            || $botId === 0) {
            $this->answer($token, $cbId, self::ANSWERS['not_found']);

            return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'refused', 'binding', $fromId);
        }

        // 6) รุ่น — revision + nonce ยังเป็นปัจจุบัน
        if ((int) $revision !== (int) $approval->revision
            || ! hash_equals((string) $approval->nonce, $nonce)) {
            $this->answer($token, $cbId, self::ANSWERS['stale_card']);

            return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'refused', 'stale', $fromId);
        }

        // 7) ข้อความ — message_id ต้องเป็นจำนวนเต็ม > 0; การ์ดที่ยังไม่รู้ id ให้ adopt (D76)
        $message = $callback['message'] ?? null;
        if (! is_array($message)) {
            return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'ignored', 'no_message', $fromId);
        }
        $messageId = $message['message_id'] ?? null;
        if (! is_int($messageId) || $messageId <= 0) {
            $this->answer($token, $cbId, self::ANSWERS['not_found']);

            return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'refused', 'message_id', $fromId);
        }

        $state = (string) $approval->telegram_delivery_state;
        if (in_array($state, ['sending', 'unknown'], true) && $approval->telegram_message_id === null) {
            // adopt: UPDATE แบบมีเงื่อนไขครั้งเดียว — แพ้แข่งก็อ่านซ้ำแล้วใช้กฎของ sent
            DB::table('follow_up_approvals')
                ->where('id', $approvalId)
                ->whereIn('telegram_delivery_state', ['sending', 'unknown'])
                ->whereNull('telegram_message_id')
                ->update([
                    'telegram_delivery_state' => 'sent',
                    'telegram_message_id' => $messageId,
                    'updated_at' => $nowStr,
                ]);
            $approval = $this->approvalRow($approvalId);
        }

        if ((string) $approval->telegram_delivery_state !== 'sent'
            || (int) $approval->telegram_message_id !== $messageId) {
            $this->answer($token, $cbId, self::ANSWERS['not_found']);

            return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'refused', 'wrong_card', $fromId);
        }

        // 8) ปุ่ม ตามโค้ดและสถานะบัตร
        if ($code === 'fe' || $code === 'fc') {
            $this->answer($token, $cbId, self::ANSWERS['not_ready']);

            return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'refused', 'not_ready', $fromId);
        }

        $status = (string) $approval->status;
        if ($status !== 'pending') {
            $answer = $this->answerForState($code, $log, $approval, $nowStr);
            $this->answer($token, $cbId, $answer);

            return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'refused', 'status_'.$status, $fromId);
        }

        if ($nowStr >= (string) $approval->expires_at) {
            $this->answer($token, $cbId, self::ANSWERS['expired']);

            return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'refused', 'expired', $fromId);
        }

        if ($code === 'fk') {
            return $this->writeTap((int) $approval->id, (int) $log->id, 'skipped', $token, $cbId, $code, $updateId, $fromIdStr, $fromId, $botId, (int) $revision, $nonce, $nowStr);
        }

        // fs — บัตรที่แก้แล้วยังไม่พร้อมส่งจนกว่า B6c
        if ((int) $approval->revision !== 1 || (string) $approval->text !== (string) $log->draft) {
            $this->answer($token, $cbId, self::ANSWERS['not_ready']);

            return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'refused', 'edited', $fromId);
        }

        // ประตูอัตโนมัติ (D78) — ตันตอบแจ้งแล้วค้าง pending ห้ามส่งเงียบๆ
        $logModel = LeadRecoveryLog::query()->find((int) $log->id);
        if ($logModel === null) {
            $this->answer($token, $cbId, self::ANSWERS['not_found']);

            return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'refused', 'not_found', $fromId);
        }
        $reason = FollowUpEligibility::recheck($logModel, $now, true);
        if ($reason !== null) {
            $this->answer($token, $cbId, $this->gateAnswer($reason));

            return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'refused', 'gate_'.$reason, $fromId);
        }

        return $this->writeTap((int) $approval->id, (int) $log->id, 'approved', $token, $cbId, $code, $updateId, $fromIdStr, $fromId, $botId, (int) $revision, $nonce, $nowStr);
    }

    /** ตรวจข้อมูลปุ่มเต็มรูป + id ไม่เกินค่าที่ฐานข้อมูลรับได้ */
    private function parseData(array $callback): ?array
    {
        $cbId = $callback['id'] ?? null;
        $data = $callback['data'] ?? null;
        if (! is_string($cbId) || $cbId === '' || ! is_string($data)) {
            return null;
        }

        if (preg_match(self::DATA_PATTERN, $data, $m) !== 1) {
            return null;
        }

        if (strlen($m[2]) > 18 && $m[2] > self::MAX_ID) {
            return null;
        }
        if (strlen($m[3]) > 9 && $m[3] > self::MAX_REVISION) {
            return null;
        }

        return [$m[1], (int) $m[2], (int) $m[3], $m[4]];
    }

    /** allowlist ต้องเป็น list ที่มีสมาชิก — ขาด/ว่าง/map/string = ไม่มีใครกดได้ (D77) */
    private function personAllowed(FlowPlugin $plugin, int $fromId): bool
    {
        $allow = $plugin->config['authorized_user_ids'] ?? null;

        if (! is_array($allow) || ! array_is_list($allow) || $allow === []) {
            return false;
        }

        foreach ($allow as $entry) {
            if (is_scalar($entry) && trim((string) $entry) === (string) $fromId) {
                return true;
            }
        }

        return false;
    }

    /** คำตอบจากแถวที่เพิ่งอ่าน — ใช้ทั้งก่อนเขียนและหลังแพ้แข่งในธุรกรรม */
    private function answerForState(string $code, ?object $log, object $approval, string $nowStr): string
    {
        if ($code === 'fe' || $code === 'fc') {
            return self::ANSWERS['not_ready'];
        }

        $status = (string) $approval->status;
        if ($status !== 'pending') {
            if ($code === 'fk' && in_array($status, ['approved', 'sending'], true)) {
                return self::ANSWERS['skip_too_late'];
            }

            return self::ANSWERS_ALREADY[$status] ?? self::ANSWERS['not_found'];
        }

        if ($nowStr >= (string) $approval->expires_at) {
            return self::ANSWERS['expired'];
        }

        if ($log === null || (string) $log->delivery_status !== 'pending') {
            return self::ANSWERS['not_found'];
        }

        // บัตรยังดูมีชีวิตแต่เขียนไม่ลง — การ์ดเปลี่ยนใต้มือ ใช้บัตรล่าสุดแทน
        return self::ANSWERS['stale_card'];
    }

    /** คำตอบของประตูอัตโนมัติ — capabilities_* พับเป็นคำเดียว, เหตุที่ไม่มีคำใช้ other */
    private function gateAnswer(string $reason): string
    {
        if ($reason === 'outside_hours') {
            return str_replace(
                ['{start}', '{end}'],
                [FollowUpCapabilities::SEND_START, FollowUpCapabilities::SEND_END],
                self::ANSWERS_GATE['outside_hours'],
            );
        }

        if (str_starts_with($reason, 'capabilities_')) {
            return self::ANSWERS_GATE['capabilities'];
        }

        return self::ANSWERS_GATE[$reason] ?? self::ANSWERS_GATE['other'];
    }

    /**
     * เขียนคู่แถวหนึ่งธุรกรรม (D79) — ล็อก log ก่อนแล้ว approval ตัดสินจากแถวที่อ่านใต้ล็อก
     * แพ้แข่ง = เขียนไม่ลง ตอบจากสถานะที่เจอ; สำเร็จ = ตอบคนกดแล้วเขียนการ์ดใหม่
     */
    private function writeTap(int $approvalId, int $logId, string $action, string $token, string $cbId, string $code, int $updateId, string $fromIdStr, int $fromId, int $botId, int $revision, string $nonce, string $nowStr): string
    {
        [$answerKey, $written] = DB::transaction(function () use ($approvalId, $logId, $action, $code, $updateId, $fromIdStr, $nowStr, $revision, $nonce) {
            $log = DB::table('lead_recovery_logs')->where('id', $logId)->lockForUpdate()->first();
            $approval = DB::table('follow_up_approvals')->where('id', $approvalId)->lockForUpdate()->first();

            if ($approval === null || $log === null) {
                return [self::ANSWERS['not_found'], false];
            }

            if ((string) $approval->status !== 'pending'
                || (string) $log->delivery_status !== 'pending'
                || (int) $approval->revision !== $revision
                || ! hash_equals((string) $approval->nonce, $nonce)
                || $nowStr >= (string) $approval->expires_at) {
                return [$this->answerForState($code, $log, $approval, $nowStr), false];
            }

            $lastUpdateId = $updateId > 0 ? ['last_update_id' => $updateId] : [];

            if ($action === 'approved') {
                // F มาจากแถวที่อ่านใต้ล็อก — การ์ดกับผู้ส่งเห็นค่าเดียวกันเสมอ
                $flagged = FollowUpSendService::checkFlagged((string) $approval->check_answers);

                DB::table('lead_recovery_logs')->where('id', $logId)->update([
                    'delivery_status' => 'approved',
                    'owner_action' => 'sent',
                    'owner_telegram_user_id' => $fromIdStr,
                    'owner_action_at' => $nowStr,
                    'check_overridden' => $flagged,
                    'updated_at' => $nowStr,
                ]);
                DB::table('follow_up_approvals')->where('id', $approvalId)->update(array_merge([
                    'status' => 'approved',
                    'check_overridden' => $flagged,
                    'updated_at' => $nowStr,
                ], $lastUpdateId));

                return [$flagged ? 'send_ok_flagged' : 'send_ok', true];
            }

            DB::table('lead_recovery_logs')->where('id', $logId)->update([
                'delivery_status' => 'skipped',
                'owner_action' => 'skipped',
                'owner_telegram_user_id' => $fromIdStr,
                'owner_action_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);
            DB::table('follow_up_approvals')->where('id', $approvalId)->update(array_merge([
                'status' => 'skipped',
                'updated_at' => $nowStr,
            ], $lastUpdateId));

            return ['skip_ok', true];
        });

        $this->answer($token, $cbId, self::ANSWERS[$answerKey] ?? $answerKey);

        if ($written) {
            // ล้มเหลวที่นี่ไม่เปลี่ยนแถวและไม่เปลี่ยนผลคืน
            $this->followUpTelegram->refreshCard($approvalId);
        }

        return $this->finish($approvalId, $logId, $botId, $code, $written ? $action : 'refused', $written ? 'written' : 'race', $fromId);
    }

    private function approvalRow(int $id): ?object
    {
        return DB::table('follow_up_approvals')->where('id', $id)->first();
    }

    private function logRow(int $id): ?object
    {
        return DB::table('lead_recovery_logs')->where('id', $id)->first();
    }

    private function answer(string $token, string $cbId, string $text): void
    {
        $this->telegram->answerFollowUpCallback($token, $cbId, $text, ! in_array($text, $this->toastTexts(), true));
    }

    /** @return list<string> */
    private function toastTexts(): array
    {
        return [
            self::ANSWERS['send_ok'],
            self::ANSWERS['send_ok_flagged'],
            self::ANSWERS['skip_ok'],
        ];
    }

    /** log เดียวต่อการเรียก — ไม่มี token / chat id / nonce / raw update */
    private function finish(?int $approvalId, ?int $logId, ?int $botId, string $code, string $result, string $reason, ?int $personId): string
    {
        Log::info('Follow-up approval tap', [
            'approval_id' => $approvalId,
            'log_id' => $logId,
            'bot_id' => $botId,
            'code' => $code,
            'result' => $result,
            'reason' => $reason,
            'person_id' => $personId,
        ]);

        return $result;
    }
}
