<?php

namespace App\Services\FollowUp;

use App\Models\FlowPlugin;
use App\Models\LeadRecoveryLog;
use App\Services\Payment\TelegramAlertBotService;
use App\Support\FollowUpRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

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

    /** คำตอบใหม่ของ B6c — ตรงตัวกับ b6c-strings.json (sha256 d58ff51c…) ห้ามแก้ลอยๆ */
    private const ANSWERS_B6C = [
        'edit_started' => 'ตอบกลับข้อความถัดไปในห้องนี้เพื่อพิมพ์ข้อความใหม่',
        'edit_prompt_failed' => 'เปิดช่องแก้ข้อความไม่สำเร็จ กดปุ่ม แก้ข้อความ อีกครั้ง',
        'confirm_needed' => 'ข้อความนี้แก้เองและยังไม่ผ่านการตรวจ กดปุ่ม ยืนยันส่ง บนการ์ดถ้าจะส่ง',
        'send_ok_confirmed' => 'รับคำสั่งแล้ว กำลังส่งข้อความที่แก้ให้ลูกค้า',
    ];

    /** ช่องแก้และข้อความแจ้ง (B6c) — ตรงตัวกับ b6c-strings.json */
    private const PROMPT_MARKER = '✏️ แก้ข้อความ #';

    private const PROMPT_TEMPLATE = "✏️ แก้ข้อความ #{log_id}\nตอบกลับ (Reply) ข้อความนี้ด้วยข้อความใหม่ที่จะส่งให้ลูกค้า\nไม่เกิน 400 ตัวอักษร 3 บรรทัด · หมดอายุ {dd}/{mm} {HH}:{MM}\nเปลี่ยนใจ: กด ส่ง หรือ ข้าม บนการ์ดได้เลย";

    private const PROMPT_PLACEHOLDER = 'พิมพ์ข้อความใหม่ที่จะส่งให้ลูกค้า';

    private const NOTICE_EDIT_SAVED = '✅ บันทึกข้อความใหม่ของ #{log_id} แล้ว ดูที่การ์ดด้านบน';

    private const NOTICE_EDIT_GONE = 'ข้อความแก้นี้ใช้ไม่ได้แล้ว กดปุ่ม แก้ข้อความ บนการ์ดอีกครั้ง';

    private const NOTICE_EDIT_CLOSED = 'รายการนี้ปิดไปแล้ว แก้ข้อความไม่ได้';

    private const NOTICE_EDIT_NOT_EDITOR = 'ตอบได้เฉพาะคนที่กดปุ่ม แก้ข้อความ ถ้าจะแก้เอง กดปุ่มบนการ์ดก่อน';

    private const NOTICE_EDIT_EXPIRED = 'รายการนี้หมดอายุแล้ว ไม่ได้ส่ง';

    private const INVALID_NOT_TYPED = 'รับเฉพาะข้อความที่พิมพ์เอง (ไม่รับรูป สติกเกอร์ หรือข้อความที่ส่งต่อมา)';

    private const INVALID_COMMAND = 'ข้อความขึ้นต้นด้วย / ใช้ไม่ได้';

    private const INVALID_LENGTH = 'ข้อความต้องยาว 1–400 ตัวอักษร';

    private const INVALID_LINES = 'ข้อความต้องไม่เกิน 3 บรรทัด';

    private const INVALID_CHARACTERS = 'ข้อความมีอักขระที่ส่งไม่ได้ เช่น อีโมจิแบบผสมหลายตัว แท็บ หรืออักขระที่มองไม่เห็น';

    private const INVALID_ACCOUNT_DATA = 'ข้อความมีคำที่ดูเหมือนรหัสหรือข้อมูลบัญชี ส่งให้ลูกค้าไม่ได้';

    private const INVALID_CONTACT = 'ข้อความมีลิงก์ อีเมล หรือเบอร์โทร ส่งไม่ได้';

    private const EDIT_RETRY_SUFFIX = ' — ตอบกลับข้อความ แก้ข้อความ เดิมอีกครั้งได้เลย';

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
            // D87: ปุ่มเก่าต้องได้การ์ดรุ่นล่าสุดกลับมาด้วยหนึ่งครั้ง
            $this->followUpTelegram->syncCard((int) $approval->id, $now);

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
        $status = (string) $approval->status;
        if ($status !== 'pending') {
            $answer = $this->answerForState($code, $log, $approval, $nowStr);
            $this->answer($token, $cbId, $answer);
            // D82: บัตรตัดสินแล้วต้องมีปุ่มใช้ — ระบายสถานะทับการ์ดเดิมหนึ่งครั้ง
            $this->followUpTelegram->refreshCard((int) $approval->id, $now);

            return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'refused', 'status_'.$status, $fromId);
        }

        if ($nowStr >= (string) $approval->expires_at) {
            $this->answer($token, $cbId, self::ANSWERS['expired']);

            return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'refused', 'expired', $fromId);
        }

        if ($code === 'fk') {
            return $this->writeTap((int) $approval->id, (int) $log->id, 'skipped', $token, $cbId, $code, $updateId, $fromIdStr, $fromId, $botId, (int) $revision, $nonce, $nowStr);
        }

        // คำตัดสิน E (แก้แล้ว) และ C (ผ่านการตรวจ) — ตัดสินครั้งแรกจากแถวที่อ่าน, ธุรกรรมตัดสินใหม่จากแถวใต้ล็อก
        $edited = (int) $approval->revision !== 1 || (string) $approval->text !== (string) $log->draft;
        $clean = ! FollowUpSendService::checkFlagged((string) $approval->check_answers);

        if ($code === 'fe') {
            return $this->startEdit($approval, $log, $token, $cbId, $updateId, $fromIdStr, $fromId, $botId, (int) $revision, $nonce, $now, $nowStr);
        }

        if ($code === 'fc') {
            if (! $edited || $clean) {
                // ยืนยันส่งมีไว้สำหรับบัตรแก้ที่ยังไม่ผ่านการตรวจเท่านั้น (D84) — บัตรนี้ปุ่มเดิมยังใช้อยู่
                $this->answer($token, $cbId, self::ANSWERS['stale_card']);
                $this->followUpTelegram->syncCard((int) $approval->id, $now);

                return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'refused', 'confirm_wrong_card', $fromId);
            }
        } elseif ($edited && ! $clean) {
            // fs บนบัตรแก้ที่ยังไม่ผ่านการตรวจ — ต้องกด ยืนยันส่ง หนึ่งแตะ (D84) บัตรยัง pending
            $this->answer($token, $cbId, self::ANSWERS_B6C['confirm_needed']);
            $this->followUpTelegram->syncCard((int) $approval->id, $now);

            return $this->finish((int) $approval->id, (int) $log->id, $botId, $code, 'refused', 'confirm_needed', $fromId);
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

    /**
     * ปุ่ม แก้ข้อความ (fe, B6c) — โพสต์ช่อง ForceReply ตอบกลับการ์ดหนึ่งครั้งแล้วผูก (editor, prompt id)
     * สถานะบัตรคง pending (D83): ส่ง/ข้ามยังทำงาน และ Edit รอบใหม่แทนที่การผูกเสมอ (D88)
     */
    private function startEdit(object $approval, object $log, string $token, string $cbId, int $updateId, string $fromIdStr, int $fromId, int $botId, int $revision, string $nonce, CarbonImmutable $now, string $nowStr): string
    {
        $approvalId = (int) $approval->id;
        $logId = (int) $log->id;

        // update เดิมส่งซ้ำ — ตอบเหมือนเดิม ไม่โพสต์ช่องใหม่
        if ($updateId > 0 && (int) $approval->last_update_id === $updateId) {
            $this->answer($token, $cbId, self::ANSWERS_B6C['edit_started']);

            return $this->finish($approvalId, $logId, $botId, 'fe', 'refused', 'repeat_update', $fromId);
        }

        $expires = (string) $approval->expires_at;
        $prompt = str_replace(
            ['{log_id}', '{dd}', '{mm}', '{HH}', '{MM}'],
            [(string) $logId, substr($expires, 8, 2), substr($expires, 5, 2), substr($expires, 11, 2), substr($expires, 14, 2)],
            self::PROMPT_TEMPLATE,
        );

        $outcome = $this->telegram->sendFollowUpText(
            $token,
            (string) $approval->telegram_chat_id,
            $prompt,
            (int) $approval->telegram_message_id,
            self::PROMPT_PLACEHOLDER,
        );
        if ($outcome['outcome'] !== 'accepted') {
            $this->answer($token, $cbId, self::ANSWERS_B6C['edit_prompt_failed']);

            return $this->finish($approvalId, $logId, $botId, 'fe', 'refused', 'prompt_'.$outcome['outcome'], $fromId);
        }

        // UPDATE มีเงื่อนไขครั้งเดียว — แพ้แข่งอ่านซ้ำแล้วใช้กฎของสถานะที่เจอ
        $updates = [
            'editor_telegram_user_id' => $fromIdStr,
            'edit_prompt_message_id' => (int) $outcome['message_id'],
            'updated_at' => $nowStr,
        ];
        if ($updateId > 0) {
            $updates['last_update_id'] = $updateId;
        }
        $written = DB::table('follow_up_approvals')
            ->where('id', $approvalId)
            ->where('status', 'pending')
            ->where('revision', $revision)
            ->where('nonce', $nonce)
            ->update($updates) === 1;

        if (! $written) {
            $fresh = $this->approvalRow($approvalId);
            $freshLog = $fresh === null ? null : $this->logRow((int) $fresh->lead_recovery_log_id);
            $answer = $fresh === null ? self::ANSWERS['not_found'] : $this->answerForState('fe', $freshLog, $fresh, $nowStr);
            $this->answer($token, $cbId, $answer);
            if ($fresh !== null && (string) $fresh->status !== 'pending') {
                $this->followUpTelegram->refreshCard($approvalId, $now);
            }

            return $this->finish($approvalId, $logId, $botId, 'fe', 'refused', 'bind_race', $fromId);
        }

        $this->answer($token, $cbId, self::ANSWERS_B6C['edit_started']);

        return $this->finish($approvalId, $logId, $botId, 'fe', 'prompted', 'prompt_open', $fromId);
    }

    /**
     * ข้อความที่ตอบช่องแก้ (B6c) — route ส่ง update แบบ message เข้ามาเท่านั้น
     * คืน saved | refused | ignored; ตอบ 200 ที่ route เสมอ (D80)
     * ข้อความที่พิมพ์ห้ามลง log ห้าม echo ในข้อความแจ้ง เข้า Telegram เฉพาะบนการ์ดหลังเซฟ (D88)
     */
    public function editReply(FlowPlugin $plugin, array $message, int $updateId, ?CarbonImmutable $now = null): string
    {
        $now = ($now ?? CarbonImmutable::now(FollowUpCapabilities::TIMEZONE))
            ->tz(FollowUpCapabilities::TIMEZONE)
            ->startOfSecond();
        $nowStr = $now->format('Y-m-d H:i:s');
        $botId = (int) DB::table('flows')->where('id', $plugin->flow_id)->value('bot_id');

        // 1) ห้อง — ต่างห้องเงียบ
        $rawChatId = $message['chat']['id'] ?? null;
        if (! is_int($rawChatId) && ! is_string($rawChatId)) {
            return $this->finishEdit(null, null, $botId, 'ignored', 'room', null);
        }
        $chatId = (string) $rawChatId;
        if ($chatId === '' || $chatId !== (string) ($plugin->config['chat_id'] ?? '')) {
            return $this->finishEdit(null, null, $botId, 'ignored', 'room', null);
        }

        // 2) รูปร่างการตอบกลับ — ต้องตอบช่องแก้ของบอทเอง
        $reply = $message['reply_to_message'] ?? null;
        $replyId = is_array($reply) ? ($reply['message_id'] ?? null) : null;
        if (
            ! is_array($reply)
            || ! is_int($replyId) || $replyId <= 0
            || (($reply['from']['is_bot'] ?? null) !== true)
            || ! is_string($reply['text'] ?? null)
            || ! str_starts_with($reply['text'], self::PROMPT_MARKER)
        ) {
            return $this->finishEdit(null, null, $botId, 'ignored', 'reply_shape', null);
        }

        // 3) คน — from.id เป็นจำนวนเต็ม > 0 และอยู่ใน allowlist ที่ชัดเจน (D77)
        $fromId = $message['from']['id'] ?? null;
        if (! is_int($fromId) || $fromId <= 0 || ! $this->personAllowed($plugin, $fromId)) {
            return $this->finishEdit(null, null, $botId, 'ignored', 'person', is_int($fromId) && $fromId > 0 ? $fromId : null);
        }
        $fromIdStr = (string) $fromId;

        $token = (string) ($plugin->config['access_token'] ?? '');
        $noticeReplyId = is_int($message['message_id'] ?? null) && $message['message_id'] > 0 ? $message['message_id'] : null;

        // 4) การผูก — บัตรเดียวเท่านั้นที่ผูกช่องนี้ไว้กับ plugin นี้ ห้องนี้ บอทนี้
        $bound = $botId === 0 ? collect() : DB::table('follow_up_approvals as a')
            ->join('lead_recovery_logs as l', 'l.id', '=', 'a.lead_recovery_log_id')
            ->where('a.telegram_plugin_id', (int) $plugin->id)
            ->where('a.telegram_chat_id', $chatId)
            ->where('a.edit_prompt_message_id', $replyId)
            ->where('l.bot_id', $botId)
            ->orderBy('a.id')
            ->get(['a.id as approval_id', 'l.id as log_id']);
        if (count($bound) !== 1) {
            $this->notice($token, $chatId, $noticeReplyId, self::NOTICE_EDIT_GONE);

            return $this->finishEdit(null, null, $botId, 'refused', 'binding', $fromId);
        }
        $approvalId = (int) $bound[0]->approval_id;
        $logId = (int) $bound[0]->log_id;

        $approval = $this->approvalRow($approvalId);
        $log = $this->logRow($logId);
        if ($approval === null || $log === null) {
            $this->notice($token, $chatId, $noticeReplyId, self::NOTICE_EDIT_GONE);

            return $this->finishEdit($approvalId, $logId, $botId, 'refused', 'binding', $fromId);
        }

        // 5) ส่งซ้ำ — update เดิมเงียบๆ ไม่ตอบไม่เขียน
        if ($updateId > 0 && (int) $approval->last_update_id === $updateId) {
            return $this->finishEdit($approvalId, $logId, $botId, 'ignored', 'repeat_update', $fromId);
        }

        // 6+7) คนแก้ / ยังเปิดไหม
        $refusal = $this->editOpenRefusal($approval, $log, $fromIdStr, $nowStr);
        if ($refusal !== null) {
            $this->notice($token, $chatId, $noticeReplyId, $refusal);

            return $this->finishEdit($approvalId, $logId, $botId, 'refused', 'not_open', $fromId);
        }

        // 8) ชนิด — ข้อความพิมพ์เองเท่านั้น ไม่รับรูป/สติกเกอร์/ข้อความส่งต่อ/ผ่านบอท
        $raw = $message['text'] ?? null;
        $forwarded = isset($message['forward_origin'])
            || isset($message['forward_from'])
            || isset($message['forward_date'])
            || isset($message['via_bot']);
        if (! is_string($raw) || $forwarded) {
            $this->notice($token, $chatId, $noticeReplyId, self::INVALID_NOT_TYPED.self::EDIT_RETRY_SUFFIX);

            return $this->finishEdit($approvalId, $logId, $botId, 'refused', 'invalid_kind', $fromId);
        }

        // 9) กติกาข้อความของเจ้านาย (D86) — ตัดสินที่กฎแรกที่ผิด การผูกคงอยู่
        $invalid = $this->editInvalidReason($raw);
        if ($invalid !== null) {
            $this->notice($token, $chatId, $noticeReplyId, $invalid.self::EDIT_RETRY_SUFFIX);

            return $this->finishEdit($approvalId, $logId, $botId, 'refused', 'invalid_text', $fromId);
        }
        $text = $this->normalizeEditText($raw);

        // 10) เซฟรุ่นใหม่ — ธุรกรรมเดียว ล็อก log ก่อนแล้ว approval
        $saveNotice = DB::transaction(function () use ($approvalId, $logId, $fromIdStr, $replyId, $updateId, $text, $nowStr) {
            $lockedLog = DB::table('lead_recovery_logs')->where('id', $logId)->lockForUpdate()->first();
            $locked = DB::table('follow_up_approvals')->where('id', $approvalId)->lockForUpdate()->first();
            if ($locked === null || $lockedLog === null) {
                return self::NOTICE_EDIT_GONE;
            }

            if ((string) $locked->editor_telegram_user_id !== $fromIdStr) {
                return self::NOTICE_EDIT_NOT_EDITOR;
            }
            if ((string) $locked->status !== 'pending' || (string) $lockedLog->delivery_status !== 'pending') {
                return self::NOTICE_EDIT_CLOSED;
            }
            if ($nowStr >= (string) $locked->expires_at) {
                return self::NOTICE_EDIT_EXPIRED;
            }
            if ((int) $locked->edit_prompt_message_id !== $replyId) {
                // ปุ่ม Edit ใหม่แทนที่การผูกแล้ว — ช่องที่ตอบคือช่องเก่า
                return self::NOTICE_EDIT_GONE;
            }

            $updates = [
                'text' => $text,
                'revision' => (int) $locked->revision + 1,
                'nonce' => $this->newNonce(),
                'check_answers' => null,
                'check_overridden' => false,
                'confirmed_revision' => null,
                'updated_at' => $nowStr,
            ];
            if ($updateId > 0) {
                $updates['last_update_id'] = $updateId;
            }
            DB::table('follow_up_approvals')->where('id', $approvalId)->update($updates);

            return null;
        });

        if ($saveNotice !== null) {
            $this->notice($token, $chatId, $noticeReplyId, $saveNotice);

            return $this->finishEdit($approvalId, $logId, $botId, 'refused', 'save_race', $fromId);
        }

        // 11) ระบายการ์ดก่อนแจ้งคน — ล้มทั้งที่ไม่เปลี่ยนแถวและไม่เปลี่ยนผลคืน
        $this->followUpTelegram->syncCard($approvalId, $now);
        $this->notice($token, $chatId, $noticeReplyId, str_replace('{log_id}', (string) $logId, self::NOTICE_EDIT_SAVED));

        return $this->finishEdit($approvalId, $logId, $botId, 'saved', 'saved', $fromId);
    }

    /** ข้อความปฏิเสธจากแถวที่อ่าน — editor ไม่ตรง / ปิดแล้ว / หมดอายุ; null = เปิดให้แก้ */
    private function editOpenRefusal(object $approval, object $log, string $fromIdStr, string $nowStr): ?string
    {
        if ((string) $approval->editor_telegram_user_id !== $fromIdStr) {
            return self::NOTICE_EDIT_NOT_EDITOR;
        }
        if ((string) $approval->status !== 'pending' || (string) $log->delivery_status !== 'pending') {
            return self::NOTICE_EDIT_CLOSED;
        }
        if ($nowStr >= (string) $approval->expires_at) {
            return self::NOTICE_EDIT_EXPIRED;
        }

        return null;
    }

    /** กฎข้อความของเจ้านาย (D86) — คืนข้อความแจ้งของกฎแรกที่ผิด, null = ผ่านทุกข้อ */
    private function editInvalidReason(string $raw): ?string
    {
        if (str_starts_with($raw, '/')) {
            return self::INVALID_COMMAND;
        }

        $text = $this->normalizeEditText($raw);

        $codePoints = mb_strlen($text, 'UTF-8');
        $max = (int) config('customer_follow_up.max_text_code_points', 400);
        if ($codePoints < 1 || $codePoints > $max) {
            return self::INVALID_LENGTH;
        }

        $lines = 0;
        foreach (explode("\n", $text) as $line) {
            if (trim($line) !== '') {
                $lines++;
            }
        }
        if ($lines > 3) {
            return self::INVALID_LINES;
        }

        try {
            FollowUpRedactor::assertSafeOutbound($text);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage() === 'account_data'
                ? self::INVALID_ACCOUNT_DATA
                : self::INVALID_CHARACTERS; // unreadable / control_character / redaction_marker
        }

        // เจ้านายเขียน @ กับราคาได้ (D86) แต่ลิงก์/อีเมล/เบอร์โทรยังห้าม — redact เปลี่ยนข้อความ = มีชิ้นที่ห้าม
        if (FollowUpRedactor::redact($text) !== $text) {
            return self::INVALID_CONTACT;
        }

        return null;
    }

    /** ปรับข้อความก่อนตรวจและก่อนเซฟ: CRLF และ CR เดี่ยว → LF แล้ว trim */
    private function normalizeEditText(string $raw): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $raw));
    }

    /** แจ้งคนหนึ่งข้อความ ตอบกลับข้อความของเขา — ข้อความล้วน ไม่มี parse_mode */
    private function notice(string $token, string $chatId, ?int $replyToMessageId, string $text): void
    {
        $this->telegram->sendFollowUpText($token, $chatId, $text, $replyToMessageId, null);
    }

    /** nonce รุ่นใหม่หลังเซฟข้อความที่แก้ — seam สำหรับ test ให้ pin ค่าได้ */
    protected function newNonce(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** log เดียวต่อการเรียก editReply — ไม่มี token / chat id / nonce / ข้อความที่พิมพ์ */
    private function finishEdit(?int $approvalId, ?int $logId, ?int $botId, string $result, string $reason, ?int $personId): string
    {
        Log::info('Follow-up edit reply', [
            'approval_id' => $approvalId,
            'log_id' => $logId,
            'bot_id' => $botId,
            'result' => $result,
            'reason' => $reason,
            'person_id' => $personId,
        ]);

        return $result;
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
        [$answerKey, $written, $repaint] = DB::transaction(function () use ($approvalId, $logId, $action, $code, $updateId, $fromIdStr, $nowStr, $revision, $nonce) {
            $log = DB::table('lead_recovery_logs')->where('id', $logId)->lockForUpdate()->first();
            $approval = DB::table('follow_up_approvals')->where('id', $approvalId)->lockForUpdate()->first();

            if ($approval === null || $log === null) {
                return [self::ANSWERS['not_found'], false, false];
            }

            if ((string) $approval->status !== 'pending'
                || (string) $log->delivery_status !== 'pending'
                || (int) $approval->revision !== $revision
                || ! hash_equals((string) $approval->nonce, $nonce)
                || $nowStr >= (string) $approval->expires_at) {
                $answer = $this->answerForState($code, $log, $approval, $nowStr);

                // D82: สถานะตัดสินแล้วที่เจอใต้ล็อก — ตอบแล้วระบายการ์ดให้ปุ่มใช้ได้
                return [$answer, false, (string) $approval->status !== 'pending'];
            }

            $lastUpdateId = $updateId > 0 ? ['last_update_id' => $updateId] : [];

            if ($action === 'approved') {
                // E/C ตัดสินจากแถวที่อ่านใต้ล็อก — การ์ดกับผู้ส่งเห็นค่าเดียวกันเสมอ
                $edited = (int) $approval->revision !== 1 || (string) $approval->text !== (string) $log->draft;
                $clean = ! FollowUpSendService::checkFlagged((string) $approval->check_answers);

                if ($code === 'fs' && $edited && ! $clean) {
                    // การ์ดแก้ใหม่และยังไม่ผ่านตรวจตอนกด — ยืนยันก่อน ห้ามเขียน (D84)
                    return [self::ANSWERS_B6C['confirm_needed'], false, false];
                }
                if ($code === 'fc' && (! $edited || $clean)) {
                    // ยืนยันส่งใช้กับบัตรแก้ที่ยังไม่ผ่านตรวจเท่านั้น — ใช้การ์ดล่าสุดแทน
                    return [self::ANSWERS['stale_card'], false, false];
                }

                $ownerAction = $edited ? LeadRecoveryLog::OWNER_ACTION_EDITED : LeadRecoveryLog::OWNER_ACTION_SENT;
                $overridden = $edited ? false : ! $clean;
                if ($code === 'fc') {
                    // แตะเดียวส่งทั้งที่ยังไม่ผ่านตรวจ — บันทึกว่าเจ้านายยอมรับเอง (D84)
                    $overridden = true;
                }

                DB::table('lead_recovery_logs')->where('id', $logId)->update([
                    'delivery_status' => 'approved',
                    'owner_action' => $ownerAction,
                    'owner_telegram_user_id' => $fromIdStr,
                    'owner_action_at' => $nowStr,
                    'check_overridden' => $overridden,
                    'updated_at' => $nowStr,
                ]);
                DB::table('follow_up_approvals')->where('id', $approvalId)->update(array_merge([
                    'status' => 'approved',
                    'check_overridden' => $overridden,
                    'confirmed_revision' => $code === 'fc' ? (int) $approval->revision : null,
                    'updated_at' => $nowStr,
                ], $lastUpdateId));

                $answerKey = $code === 'fc'
                    ? 'send_ok_confirmed'
                    : ($edited || $clean ? 'send_ok' : 'send_ok_flagged');

                return [$answerKey, true, true];
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

            return ['skip_ok', true, true];
        });

        $this->answer($token, $cbId, $this->answerText($answerKey));

        if ($written || $repaint) {
            // ล้มเหลวที่นี่ไม่เปลี่ยนแถวและไม่เปลี่ยนผลคืน
            $this->followUpTelegram->refreshCard($approvalId);
        }

        return $this->finish($approvalId, $logId, $botId, $code, $written ? $action : 'refused', $written ? 'written' : 'race', $fromId);
    }

    /** คำตอบจาก key — รวมคำตอบเดิม (B6b) และคำตอบใหม่ (B6c) */
    private function answerText(string $key): string
    {
        return self::ANSWERS[$key] ?? self::ANSWERS_B6C[$key] ?? $key;
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
            self::ANSWERS_B6C['edit_started'],
            self::ANSWERS_B6C['send_ok_confirmed'],
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
