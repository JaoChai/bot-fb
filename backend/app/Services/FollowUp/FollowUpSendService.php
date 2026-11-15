<?php

namespace App\Services\FollowUp;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\LeadRecoveryLog;
use App\Models\Message;
use App\Services\LINEService;
use App\Support\FollowUpRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * ตัวส่งข้อความตามลูกค้าตัวเดียวของระบบ (การ์ด B4c — D46-D52, plan §3.6)
 *
 * เส้นทางหนึ่ง call:
 *  Step A  อ่านอย่างเดียว: บัตร/แถว + (เฉพาะแถวที่ยังไม่ยิง) owner_action/revision/flag → blocked หรือ already_sent
 *          โดยไม่เขียน ไม่ยิงอะไร; แถวที่ยิงแล้วเลื่อนเช็ค owner ไปหลัง claim เป็น gate แรก (D54) จบ send_failed เสมอ
 *  Step B  claim แบบ compare-and-set all-or-nothing (D55) ใน transaction เดียว ล็อกทั้งสองแถว:
 *          approved→sending, retry ถึงคิว→sending, sending ค้าง 10 นาที (worker ตาย)→sending;
 *          เขียน claim_token ใหม่ + retry_key เดิมหรือ UUID ใหม่ — อัปเดตไหนไม่ครบ 1 แถว = ย้อนทั้งหมด
 *  Step C  gate ตามลำดับ: owner (แถวยิงแล้ว, D54) → expiry → recheck(forSend: true) → ผู้รับ → ข้อความ (ยังไม่ยิงเท่านั้น)
 *          → โควต้า → ข้อความใหม่ล่าสุด
 *  Step D  freeze (first_push_at / message_sent / attempt_number) ก่อน POST เสมอ แล้ว pushFollowUp นอก transaction
 *          payload แช่งตาย (D49): ยิงซ้ำใช้ message_sent + retry_key ที่เก็บไว้ ห้ามอ่าน approval.text อีก
 *
 * หลัง LINE ยอมรับ: เขียนประวัติ (แถวบอทติด tag follow_up) + สถานะ sent + ตัวชี้ห้อง ใน transaction เดียว
 * แล้ว broadcast หลัง commit — broadcast พัง = ผลยังเป็น sent
 *
 * คืนค่าเสมอหนึ่งใน sent / already_sent / blocked / retry / failed — ไม่ throw กับสถานะที่อ่านได้
 * (database ล้มประหลาดอาจโยนต่อ) และ log บรรทัดเดียวต่อ call: approval id, log id, bot id,
 * ค่าที่คืน, reason — ไม่มีข้อความ / ผู้รับ / retry key / token / ข้อความ exception หลุดลง log
 */
class FollowUpSendService
{
    /** ส่งได้สูงสุด 3 ครั้งต่อแถว (นับที่ attempt_number — ตัวนับฝั่งสายร่างเป็นอีกคอลัมน์, D50) */
    private const MAX_PUSHES = 3;

    /** รอหลังคำตอบ retryable ครั้งแรก (วินาที) */
    private const RETRY_WAIT_FIRST = 60;

    /** รอหลังคำตอบ retryable ครั้งที่สอง (วินาที) */
    private const RETRY_WAIT_SECOND = 300;

    /** แถว sending ที่ updated_at เก่ากว่านี้ถือว่า worker ตาย (นาที) */
    private const CLAIM_STALE_MINUTES = 10;

    public function __construct(
        private readonly LINEService $lineService,
        private readonly FollowUpQuotaService $quota,
    ) {}

    /**
     * ส่งข้อความตามลูกค้าของบัตรอนุมัติหนึ่งใบ
     *
     * @param  int  $approvalId  id ของ follow_up_approvals (บัตรที่อนุมัติแล้วเท่านั้น)
     * @param  CarbonImmutable|null  $now  จังหวะเวลา (ทดสอบใช้เดินนาฬิกา); null = ตอนนี้กรุงเทพฯ
     * @return string sent|already_sent|blocked|retry|failed
     */
    public function send(int $approvalId, ?CarbonImmutable $now = null): string
    {
        $now = ($now ?? CarbonImmutable::now(FollowUpCapabilities::TIMEZONE))
            ->tz(FollowUpCapabilities::TIMEZONE)
            ->startOfSecond();
        $nowStr = $now->format('Y-m-d H:i:s');

        // ---- Step A: อ่านอย่างเดียว เขียนไม่ได้ ยิงไม่ได้ ----
        $approval = DB::table('follow_up_approvals')->where('id', $approvalId)->first();
        if ($approval === null) {
            return $this->finish($approvalId, null, null, 'blocked', 'missing_approval');
        }

        $log = DB::table('lead_recovery_logs')->where('id', $approval->lead_recovery_log_id)->first();
        if ($log === null) {
            return $this->finish($approvalId, (int) $approval->lead_recovery_log_id, null, 'blocked', 'missing_log');
        }

        $logId = (int) $log->id;
        $botId = (int) $log->bot_id;

        if ($log->delivery_status === LeadRecoveryLog::DELIVERY_STATUS_SENT && $log->message_id !== null) {
            return $this->finish($approvalId, $logId, $botId, 'already_sent', null);
        }

        // D54: แถวที่ยิงแล้ว (first_push_at ไม่ว่าง) เช็ค owner หลัง claim เป็น gate แรก —
        // บล็อกแบบไม่เขียนตรงนี้จะทิ้งแถวค้าง retry กินสล็อตโควต้าตลอด; already_sent/missing row ยังตรวจข้างบน
        if ($log->first_push_at === null) {
            $ownerReason = self::ownerGateReason($log, $approval);
            if ($ownerReason !== null) {
                return $this->finish($approvalId, $logId, $botId, 'blocked', $ownerReason);
            }
        }

        // ---- Step B: claim (transaction เดียว, compare-and-set) ----
        $token = (string) Str::uuid();
        $claimed = $this->claim($approvalId, $logId, $token, $now, $nowStr);
        if (! $claimed) {
            return $this->finish($approvalId, $logId, $botId, 'blocked', 'claim_refused');
        }

        // ---- Step C: gate ตามลำดับ — ก่อน freeze ก่อน POST ----
        $fresh = DB::table('lead_recovery_logs')->where('id', $logId)->first();
        $freshApproval = DB::table('follow_up_approvals')->where('id', $approvalId)->first();
        if ($fresh === null || $freshApproval === null) {
            return $this->finish($approvalId, $logId, $botId, 'blocked', 'missing_log');
        }

        $pushed = $fresh->first_push_at !== null;
        $logModel = LeadRecoveryLog::query()->find($logId);
        $conversation = $logModel?->conversation()->first();
        $recipient = (string) ($conversation->external_customer_id ?? '');

        // 0) owner gate ของแถวที่ยิงแล้ว (D54) — gate แรกหลัง claim: พังที่ไหนก็จบ send_failed
        //    เพราะยิงออกไปแล้วผลรับไม่ได้รู้ผล ห้ามปล่อยแถวค้าง retry แบบไม่เขียนอะไรเลย
        if ($pushed && self::ownerGateReason($fresh, $freshApproval) !== null) {
            return $this->endWrite($approvalId, $logId, $botId, $token, 'send_failed', 'cancelled', 'acceptance_unknown_gate_changed', 'failed', $nowStr);
        }

        // 1) expiry — วินาทีเท่ากันนับว่าหมดอายุ
        if ($nowStr >= (string) $freshApproval->expires_at) {
            return $pushed
                ? $this->endWrite($approvalId, $logId, $botId, $token, 'send_failed', 'expired', 'acceptance_unknown_expired', 'failed', $nowStr)
                : $this->endWrite($approvalId, $logId, $botId, $token, 'expired', 'expired', 'expired_before_send', 'blocked', $nowStr);
        }

        // 2) recheck (forSend: งบร่างรายวันไม่มีสิทธิ์ตัดตอนส่ง — D46)
        $reason = $logModel === null ? 'room_not_active' : FollowUpEligibility::recheck($logModel, $now, true);
        if ($reason !== null) {
            if ($pushed) {
                return $this->endWrite($approvalId, $logId, $botId, $token, 'send_failed', 'cancelled', 'acceptance_unknown_gate_changed', 'failed', $nowStr);
            }

            if (in_array($reason, ['new_customer_message', 'new_bot_message', 'order_created', 'passed_slip'], true)) {
                return $this->endWrite($approvalId, $logId, $botId, $token, 'cancelled', 'cancelled', $reason, 'blocked', $nowStr);
            }

            // นอกเวลา / บอทหรือฟีเจอร์ปิด / handover / ห้องไม่ active — คืนที่ approved รอรอบหน้า
            return $this->endWrite($approvalId, $logId, $botId, $token, 'approved', 'approved', $reason, 'blocked', $nowStr);
        }

        // 3) ผู้รับ (D52) — สองสถานะยิงเท่ากัน: รู้แน่ว่ารูปร่างผิด ส่งไม่ได้ตลอด
        if (preg_match('/^U[0-9a-f]{32}$/', $recipient) !== 1) {
            return $this->endWrite($approvalId, $logId, $botId, $token, 'send_failed', 'cancelled', 'recipient_invalid', 'failed', $nowStr);
        }

        // 4) ข้อความ — เฉพาะแถวที่ยังไม่เคยยิง; แถวยิงแล้วส่ง message_sent ที่แช่งไว้ ไม่เช็คซ้ำ (D49)
        if (! $pushed) {
            $text = (string) $freshApproval->text;
            $length = mb_strlen($text, 'UTF-8');
            $nonBlankLines = count(array_filter(explode("\n", $text), fn (string $line): bool => trim($line) !== ''));
            $unsafe = $length < 1
                || $length > (int) config('customer_follow_up.max_text_code_points', 400)
                || $nonBlankLines > 3;

            if (! $unsafe) {
                try {
                    FollowUpRedactor::assertSafeOutbound($text);
                } catch (InvalidArgumentException) {
                    $unsafe = true;
                }
            }

            if ($unsafe) {
                return $this->endWrite($approvalId, $logId, $botId, $token, 'send_failed', 'cancelled', 'unsafe_text', 'failed', $nowStr);
            }
        }

        // 5) โควต้า — อ่านใหม่เสมอ ไม่มีแคช (D43)
        $bot = Bot::query()->find($botId);
        $quota = $bot === null ? ['allowed' => false, 'reason' => 'bot_switched_off'] : $this->quota->check($bot, $now, $logId);
        if (! $quota['allowed']) {
            return $pushed
                ? $this->waitWrite($approvalId, $logId, $botId, $token, (string) $quota['reason'], $now->addSeconds(self::RETRY_WAIT_SECOND)->format('Y-m-d H:i:s'), 'retry', $nowStr)
                : $this->endWrite($approvalId, $logId, $botId, $token, 'approved', 'approved', (string) $quota['reason'], 'blocked', $nowStr);
        }

        // 6) last look — ก่อน POST เท่านั้น: ข้อความใหม่แลนด์ระหว่างทาง = ยกเลิก / ไม่รู้ผล
        $maxId = (int) DB::table('messages')->where('conversation_id', (int) $fresh->conversation_id)->max('id');
        if ($maxId !== (int) $fresh->observed_last_message_id) {
            return $pushed
                ? $this->endWrite($approvalId, $logId, $botId, $token, 'send_failed', 'cancelled', 'acceptance_unknown_gate_changed', 'failed', $nowStr)
                : $this->endWrite($approvalId, $logId, $botId, $token, 'cancelled', 'cancelled', 'message_arrived', 'blocked', $nowStr);
        }

        // ---- Step D: freeze แล้วยิงครั้งเดียวนอก transaction ----
        if ($pushed && (int) $fresh->attempt_number >= self::MAX_PUSHES) {
            return $this->endWrite($approvalId, $logId, $botId, $token, 'send_failed', 'cancelled', 'acceptance_unknown_attempts', 'failed', $nowStr);
        }

        $attempt = (int) $fresh->attempt_number + 1;
        $conversationId = (int) $fresh->conversation_id;
        $messageSent = filled($fresh->message_sent) ? $fresh->message_sent : (string) $freshApproval->text;
        $frozen = DB::table('lead_recovery_logs')
            ->where('id', $logId)
            ->where('claim_token', $token)
            ->update([
                'first_push_at' => $fresh->first_push_at ?? $nowStr,
                'message_sent' => $messageSent,
                'attempt_number' => $attempt,
                'updated_at' => $nowStr,
            ]) === 1;
        if (! $frozen) {
            return $this->finish($approvalId, $logId, $botId, 'blocked', 'claim_lost');
        }

        $result = $this->lineService->pushFollowUp($bot, $recipient, $messageSent, (string) $fresh->retry_key);

        switch ($result['outcome']) {
            case 'accepted':
                return $this->writeAcceptance($approvalId, $logId, $botId, $token, $conversationId, $now, $nowStr, $result['request_id']);

            case 'retryable':
                if ($attempt >= self::MAX_PUSHES) {
                    return $this->endWrite($approvalId, $logId, $botId, $token, 'send_failed', 'cancelled', 'acceptance_unknown_attempts', 'failed', $nowStr);
                }

                $wait = $attempt === 1 ? self::RETRY_WAIT_FIRST : self::RETRY_WAIT_SECOND;

                return $this->waitWrite($approvalId, $logId, $botId, $token, 'line_retryable', $now->addSeconds($wait)->format('Y-m-d H:i:s'), 'retry', $nowStr);

            default: // rejected
                $reason = 'line_rejected_'.($result['status'] !== null ? (string) $result['status'] : 'no_request');

                return $this->endWrite($approvalId, $logId, $botId, $token, 'send_failed', 'cancelled', $reason, 'failed', $nowStr);
        }
    }

    /**
     * claim — รับแถวเข้า sending เฉพาะสามทาง (transaction เดียว all-or-nothing, D55):
     * ล็อกทั้ง log และบัตรด้วย row lock อัปเดตไหนไม่ครบ 1 แถวโยน ClaimLostException = ย้อนทั้งหมด
     * (a) approved บนบัตร approved, (b) retry ที่ถึงคิว บนบัตร sending,
     * (c) sending ที่ updated_at ค้างเกิน 10 นาที (worker ตาย) บนบัตร sending
     */
    private function claim(int $approvalId, int $logId, string $token, CarbonImmutable $now, string $nowStr): bool
    {
        $newKey = (string) Str::uuid();
        $staleCutoff = $now->subMinutes(self::CLAIM_STALE_MINUTES)->format('Y-m-d H:i:s');

        try {
            return DB::transaction(function () use ($approvalId, $logId, $token, $nowStr, $newKey, $staleCutoff): bool {
                $row = DB::table('lead_recovery_logs')->where('id', $logId)->lockForUpdate()->first();
                $approval = DB::table('follow_up_approvals')->where('id', $approvalId)->lockForUpdate()->first();

                if ($row === null || $approval === null) {
                    return false;
                }

                $from = $row->delivery_status;
                $canClaim =
                    ($from === LeadRecoveryLog::DELIVERY_STATUS_APPROVED && $approval->status === 'approved')
                    || ($from === LeadRecoveryLog::DELIVERY_STATUS_RETRY
                        && $approval->status === 'sending'
                        && $row->next_attempt_at !== null
                        && (string) $row->next_attempt_at <= $nowStr)
                    || ($from === LeadRecoveryLog::DELIVERY_STATUS_SENDING
                        && $approval->status === 'sending'
                        && (string) $row->updated_at <= $staleCutoff);

                if (! $canClaim) {
                    return false;
                }

                $logMoved = DB::table('lead_recovery_logs')
                    ->where('id', $logId)
                    ->where('delivery_status', $from)
                    ->update([
                        'delivery_status' => LeadRecoveryLog::DELIVERY_STATUS_SENDING,
                        'claim_token' => $token,
                        'claimed_at' => $nowStr,
                        'updated_at' => $nowStr,
                        'retry_key' => filled($row->retry_key) ? $row->retry_key : $newKey,
                    ]) === 1;

                $approvalMoved = DB::table('follow_up_approvals')
                    ->where('id', $approvalId)
                    ->where('status', $approval->status)
                    ->update(['status' => 'sending', 'updated_at' => $nowStr]) === 1;

                // D55: claim ต้อง all-or-nothing — ทิ้งครึ่งเดียวไว้ (log sending + token แต่บัตรไม่ sending)
                // = แถวติดตายเพราะ claim (a) ต้องการ approved ส่วน (b)/(c) ต้องการบัตร sending
                if ($logMoved !== true || $approvalMoved !== true) {
                    throw new ClaimLostException;
                }

                return true;
            });
        } catch (ClaimLostException) {
            return false;
        }
    }

    /**
     * คำตอบ accepted → เขียนประวัติ + สถานะ + ตัวชี้ห้อง ใน transaction เดียว
     * (แถวล็อกด้วย id + claim token ก่อนเขียนทุกจุด)
     */
    private function writeAcceptance(int $approvalId, int $logId, int $botId, string $token, int $conversationId, CarbonImmutable $now, string $nowStr, ?string $requestId): string
    {
        try {
            $messageId = DB::transaction(function () use ($logId, $token, $nowStr, $requestId, $approvalId, $conversationId): int {
                $row = DB::table('lead_recovery_logs')->where('id', $logId)->lockForUpdate()->first();

                if ($row === null || ! hash_equals((string) ($row->claim_token ?? ''), $token)) {
                    throw new ClaimLostException;
                }

                $conversationId = (int) $row->conversation_id;
                $messageId = $row->message_id;

                if ($messageId === null) {
                    $messageId = DB::table('messages')->insertGetId([
                        'conversation_id' => $conversationId,
                        'sender' => 'bot',
                        'content' => (string) $row->message_sent,
                        'type' => 'text',
                        // D52: ต้นทุน/ท็อกเกนอยู่บนแถว log เท่านั้น — แถวประวัติไม่มีตัวเลขพวกนี้
                        'model_used' => $row->owner_action === LeadRecoveryLog::OWNER_ACTION_SENT ? $row->draft_model : null,
                        'prompt_tokens' => null,
                        'completion_tokens' => null,
                        'cost' => null,
                        'metadata' => json_encode(['follow_up' => true, 'follow_up_log_id' => $logId], JSON_UNESCAPED_UNICODE),
                        'created_at' => $nowStr,
                        'updated_at' => $nowStr,
                    ]);
                }

                $logMoved = DB::table('lead_recovery_logs')
                    ->where('id', $logId)
                    ->where('claim_token', $token)
                    ->update([
                        'message_id' => $messageId,
                        'sent_at' => $nowStr,
                        'delivery_status' => LeadRecoveryLog::DELIVERY_STATUS_SENT,
                        'line_request_id' => $requestId,
                        'reason_code' => null,
                        'next_attempt_at' => null,
                        'claim_token' => null,
                        'claimed_at' => null,
                        'updated_at' => $nowStr,
                    ]) === 1;
                if (! $logMoved) {
                    throw new ClaimLostException;
                }

                DB::table('follow_up_approvals')
                    ->where('id', $approvalId)
                    ->where('status', 'sending')
                    ->update(['status' => 'sent', 'updated_at' => $nowStr]);

                $this->pointConversation($conversationId, (int) $messageId, $nowStr);

                return (int) $messageId;
            });
        } catch (ClaimLostException) {
            return $this->finish($approvalId, $logId, $botId, 'blocked', 'claim_lost');
        } catch (Throwable) {
            // LINE ยอมรับแล้วแต่ DB ล้ม: เก็บคีย์+ข้อความแช่งไว้ นัด retry ใหม่ +60 วิ แล้วคืน retry
            // (ถ้าเขียนไม่ได้ด้วย แถวคงสถานะ sending และสาย claim (c) เก็บงานต่อใน 10 นาที)
            try {
                DB::table('lead_recovery_logs')
                    ->where('id', $logId)
                    ->where('claim_token', $token)
                    ->update([
                        'delivery_status' => LeadRecoveryLog::DELIVERY_STATUS_RETRY,
                        'reason_code' => 'line_retryable',
                        'next_attempt_at' => $now->addSeconds(self::RETRY_WAIT_FIRST)->format('Y-m-d H:i:s'),
                        'claim_token' => null,
                        'claimed_at' => null,
                        'updated_at' => $nowStr,
                    ]);
            } catch (Throwable) {
                // เจตนาเงียบ — แถว sending ค้างเป็นทางกลับมาที่สอง
            }

            return $this->finish($approvalId, $logId, $botId, 'retry', 'line_retryable');
        }

        $message = Message::query()->find($messageId);
        $conversation = Conversation::query()->find($conversationId);

        if ($message !== null && $conversation !== null) {
            try {
                $this->broadcastAccepted($message, $conversation, $logId);
            } catch (Throwable) {
                // broadcast พัง = ผลยัง sent — เขียน log ไว้ ไม่มีข้อความ exception หลุดลง log
                Log::warning('Customer follow-up send: broadcast failed', ['log_id' => $logId]);
            }
        }

        return $this->finish($approvalId, $logId, $botId, 'sent', null);
    }

    /** broadcast หลัง commit — พังแล้วผลยัง sent; จับ log ไว้ ไม่มีข้อความ exception ลง log */
    protected function broadcastAccepted(Message $message, Conversation $conversation, int $logId): void
    {
        $conversationData = [
            'id' => $conversation->id,
            'message_count' => $conversation->message_count,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'unread_count' => $conversation->unread_count,
        ];

        try {
            broadcast(new MessageSent($message, $conversationData))->toOthers();
            broadcast(new ConversationUpdated($conversation, 'message_received'))->toOthers();
        } catch (Throwable) {
            Log::warning('Customer follow-up send: broadcast failed', [
                'log_id' => $logId,
            ]);
        }
    }

    /** ตัวชี้ห้องหลังยิงสำเร็จ: นับ +1 และขยับ last_message_* เฉพาะเมื่อของใหม่ไม่ทับของที่ใหม่กว่า */
    private function pointConversation(int $conversationId, int $messageId, string $nowStr): void
    {
        $update = [
            'message_count' => DB::raw('message_count + 1'),
            'recovery_attempts' => DB::raw('recovery_attempts + 1'),
            'last_recovery_at' => $nowStr,
            'updated_at' => $nowStr,
        ];

        $newestId = (int) DB::table('messages')->where('conversation_id', $conversationId)->max('id');
        if ($newestId === $messageId) {
            $update['last_message_id'] = $messageId;
        }

        $storedLastAt = DB::table('conversations')->where('id', $conversationId)->value('last_message_at');
        if ($storedLastAt === null || (string) $storedLastAt <= $nowStr) {
            $update['last_message_at'] = $nowStr;
        }

        DB::table('conversations')->where('id', $conversationId)->update($update);
    }

    /**
     * เขียนจุดจบของ gate — log สถานะใหม่ + reason + เคลียร์ claim, approval ไปสถานะใหม่
     * ทุกคอลัมน์ owner_action/retry_key/first_push_at/message_sent/draft_* คงเดิม
     * (D55) log + approval เขียนเป็น transaction เดียว all-or-nothing — บัตรเปลี่ยนกลางทาง = ย้อนทั้งคู่
     * log คงแบบก่อนเขียนเป๊ะ; เสีย claim ระหว่างทาง = หยุด คืน blocked ไม่เขียนต่อ
     */
    private function endWrite(int $approvalId, int $logId, int $botId, string $token, string $logStatus, string $approvalStatus, string $reason, string $result, string $nowStr): string
    {
        try {
            DB::transaction(function () use ($approvalId, $logId, $token, $logStatus, $approvalStatus, $reason, $nowStr): void {
                $locked = DB::table('lead_recovery_logs')->where('id', $logId)->lockForUpdate()->first();

                if ($locked === null || ! hash_equals((string) ($locked->claim_token ?? ''), $token)) {
                    throw new ClaimLostException;
                }

                $logMoved = DB::table('lead_recovery_logs')
                    ->where('id', $logId)
                    ->where('claim_token', $token)
                    ->update([
                        'delivery_status' => $logStatus,
                        'reason_code' => $reason,
                        'claim_token' => null,
                        'claimed_at' => null,
                        'updated_at' => $nowStr,
                    ]) === 1;

                if (! $logMoved) {
                    throw new ClaimLostException;
                }

                if ($approvalStatus !== 'sending') {
                    $approvalMoved = DB::table('follow_up_approvals')
                        ->where('id', $approvalId)
                        ->where('status', 'sending')
                        ->update(['status' => $approvalStatus, 'updated_at' => $nowStr]) === 1;

                    if (! $approvalMoved) {
                        throw new ClaimLostException;
                    }
                }
            });
        } catch (ClaimLostException) {
            return $this->finish($approvalId, $logId, $botId, 'blocked', 'claim_lost');
        }

        return $this->finish($approvalId, $logId, $botId, $result, $reason);
    }

    /**
     * เขียน "รอรอบหน้า" — log กลับไป retry (หรือสถานะที่กำหนด) + next_attempt_at,
     * approval คง sending, คีย์/ข้อความแช่ง/first_push_at คงเดิม เสีย claim = blocked
     */
    private function waitWrite(int $approvalId, int $logId, int $botId, string $token, string $reason, string $nextAttemptAt, string $logStatus, string $nowStr): string
    {
        $logMoved = DB::table('lead_recovery_logs')
            ->where('id', $logId)
            ->where('claim_token', $token)
            ->update([
                'delivery_status' => $logStatus,
                'reason_code' => $reason,
                'next_attempt_at' => $nextAttemptAt,
                'claim_token' => null,
                'claimed_at' => null,
                'updated_at' => $nowStr,
            ]) === 1;

        if (! $logMoved) {
            return $this->finish($approvalId, $logId, $botId, 'blocked', 'claim_lost');
        }

        return $this->finish($approvalId, $logId, $botId, 'retry', $reason);
    }

    /** เหตุผลฝั่ง owner ที่ห้ามส่งบัตรนี้ — null = ผ่านทุกข้อ (ใช้ทั้ง Step A และ owner gate หลัง claim ของแถวยิงแล้ว) */
    private static function ownerGateReason(object $log, object $approval): ?string
    {
        if ($log->owner_action === LeadRecoveryLog::OWNER_ACTION_AUTOMATIC) {
            // D47: เส้นทาง automatic ยังไม่อยู่ในการ์ดนี้ — มาพร้อมการ์ดที่สร้างบัตร automatic
            return 'automatic_action';
        }

        if (! in_array($log->owner_action, [LeadRecoveryLog::OWNER_ACTION_SENT, LeadRecoveryLog::OWNER_ACTION_EDITED], true)
            || $log->owner_action_at === null
            || trim((string) $log->owner_telegram_user_id) === '') {
            return 'owner_action_invalid';
        }

        if ($log->owner_action === LeadRecoveryLog::OWNER_ACTION_SENT
            && ((int) $approval->revision !== 1 || $approval->text !== $log->draft)) {
            return 'revision_mismatch';
        }

        if (self::checkFlagged((string) $approval->check_answers)
            && ! self::checkConfirmed((string) $log->owner_action, $approval)) {
            return 'flag_not_confirmed';
        }

        return null;
    }

    /**
     * check_answers มีข้อห้ามหรือไม่ (D53) — สะอาดทางเดียว: decode ได้ object ที่ failed เป็น list ว่าง
     * และ error เป็น boolean false เป๊ะเท่านั้น รูปร่างอื่นทั้งหมด (อ่านไม่ได้/คีย์หาย/ชนิดผิด) = มีข้อห้าม
     */
    private static function checkFlagged(string $rawAnswers): bool
    {
        $answers = json_decode($rawAnswers, true);

        if (! is_array($answers)) {
            return true;
        }

        $failed = $answers['failed'] ?? null;
        $error = $answers['error'] ?? null;

        return ! (is_array($failed) && $failed === [] && $error === false);
    }

    /** เจ้าของยอมรับข้อห้ามหรือยัง: sent = check_overridden, edited = confirmed_revision ตรง revision */
    private static function checkConfirmed(string $ownerAction, object $approval): bool
    {
        if ($ownerAction === LeadRecoveryLog::OWNER_ACTION_SENT) {
            return (bool) $approval->check_overridden;
        }

        return (int) $approval->confirmed_revision === (int) $approval->revision;
    }

    /** จุดจบทุกเส้นทาง — log บรรทัดเดียวต่อ call ไม่มีข้อมูลลูกค้า/คีย์/token */
    private function finish(int $approvalId, ?int $logId, ?int $botId, string $result, ?string $reason): string
    {
        Log::info('Customer follow-up send', [
            'approval_id' => $approvalId,
            'log_id' => $logId,
            'bot_id' => $botId,
            'result' => $result,
            'reason' => $reason,
        ]);

        return $result;
    }
}

/** claim หายระหว่าง call (อีก worker ชิง/แถวเปลี่ยน) — ยกเลิกการเขียนทั้งหมดของ call นี้ */
class ClaimLostException extends RuntimeException {}
