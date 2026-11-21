<?php

namespace App\Services\FollowUp;

use App\Models\FollowUpApproval;
use App\Models\LeadRecoveryLog;
use App\Support\FollowUpRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ขั้นเตรียมร่าง "ตามลูกค้า" ทีละแถว — การ์ด B3c (plan brief B3, D18 ฉบับแก้, D31-D36)
 *
 * แถวเดียวเดินจาก candidate ไป "ร่างรอเจ้าของอนุมัติ" หรือจบด้วยเหตุที่บันทึกไว้:
 * claim (UPDATE เดียว, D32) → คืน slot ค้างถ้ามี (D37) → recheck (D34) → context (D31) →
 * sort → reserve (D36) → draft → check แล้วบันทึกทั้งหมดใน transaction เดียว (D35)
 *
 * ขอบเขตตายตัว: ไม่ส่งอะไรออกนอกระบบ (โค้ดส่งข้อความจริงเป็นของการ์ดถัดไป),
 * ไม่สร้าง candidate (โดยหัวการ์ด scheduler), ไม่มี transaction ค้างตอน model ยิง,
 * ทุกเวลาเขียนเป็นสตริงกรุงเทพฯ ผ่าน query builder เท่านั้น (แบบเดียวกับ B2)
 */
class FollowUpPipeline
{
    /** แถว sorting/drafting ที่เงียบเกินนาทีนี้ = worker ตาย ยืมใหม่ได้ (D32) */
    public const STALE_CLAIM_MINUTES = 10;

    /** ล้มครบจำนวนครั้งนี้ = excluded ถาวร (D33) */
    public const MAX_ATTEMPTS = 3;

    /** เว้นวรรณรอบล้ม (นาที) ก่อนลองใหม่ (D33) */
    public const RETRY_MINUTES = 30;

    /** เหตุ recheck ที่จบชั่วนี้ไปเลย — เงียบนี้ถือว่าถูกยกเลิก (D34) */
    private const CANCEL_REASONS = ['new_customer_message', 'new_bot_message', 'order_created', 'passed_slip'];

    private const DRAFT_FAILURE_REASON_SORT = 'sort_failed';

    private const DRAFT_FAILURE_REASON_DRAFT = 'draft_failed';

    public function __construct(
        private readonly FollowUpDecisionsService $decisions,
        private readonly FollowUpDraftService $drafts,
        private readonly FollowUpReservationService $reservations,
        private readonly FollowUpAutomaticRule $autoRule,
    ) {}

    public function prepare(int $logId, ?CarbonImmutable $now = null): void
    {
        // เวลาในตารางเป็นกรุงเทพฯ ไม่มีโซน — normalize ที่ประตูเดียวเหมือน B2
        // B3a: จำไว้ด้วยว่าผู้เรียกส่งนาฬิกามาเองหรือไม่ — งานจริงไม่ส่ง นาฬิกา "ตัดสินส่งเอง"
        // ต้องอ่านใหม่ ณ จุดตัดสินใน transaction (A4: owner_action_at = เวลาตัดสิน)
        $explicitNow = $now;
        $now = ($now ?? CarbonImmutable::now())
            ->tz(FollowUpCapabilities::TIMEZONE)
            ->startOfSecond();
        $nowStr = $now->format('Y-m-d H:i:s');

        // ---- ขั้น 1: claim (D32) — UPDATE เดียวที่ชนะก็ยืม, 0 แถว = จบเงียบ ๆ ----
        $before = DB::table('lead_recovery_logs')->where('id', $logId)->first([
            'id',
            'delivery_status',
            'conversation_id',
            'observed_last_message_id',
            'sales_started_at',
            'payment_step',
            'transfer_summary_sent',
            'model_cost',
        ]);
        if ($before === null) {
            return;
        }
        $claimedFrom = (string) $before->delivery_status;
        // ค่าสะสมของแถว — ทุกเส้นทาง (failRow ทุกสาเหตุ และทุก stop) ต้องบวกค่านี้ไว้
        $earlierCost = $before->model_cost === null ? null : (float) $before->model_cost;
        $staleCutoff = $now->subMinutes(self::STALE_CLAIM_MINUTES)->format('Y-m-d H:i:s');

        $claimed = DB::table('lead_recovery_logs')
            ->where('id', $logId)
            ->where(function ($query) use ($nowStr, $staleCutoff): void {
                $query
                    ->where('delivery_status', LeadRecoveryLog::DELIVERY_STATUS_CANDIDATE)
                    ->orWhere(function ($soft) use ($nowStr): void {
                        $soft->whereIn('delivery_status', [
                            LeadRecoveryLog::DELIVERY_STATUS_SORT_RETRY,
                            LeadRecoveryLog::DELIVERY_STATUS_DEFERRED,
                        ])->where(function ($due) use ($nowStr): void {
                            $due->whereNull('next_attempt_at')
                                ->orWhere('next_attempt_at', '<=', $nowStr);
                        });
                    })
                    ->orWhere(function ($stale) use ($staleCutoff): void {
                        $stale->whereIn('delivery_status', [
                            LeadRecoveryLog::DELIVERY_STATUS_SORTING,
                            LeadRecoveryLog::DELIVERY_STATUS_DRAFTING,
                        ])->where('updated_at', '<=', $staleCutoff);
                    });
            })
            ->update([
                'delivery_status' => LeadRecoveryLog::DELIVERY_STATUS_SORTING,
                'updated_at' => $nowStr,
            ]);
        if ($claimed === 0) {
            return;
        }

        $log = LeadRecoveryLog::query()->findOrFail($logId);

        // D37: แถวที่ยังถือ slot ค้างของวันนี้ (worker ตายก่อนได้ร่าง) — คืน slot ก่อนเสมอ
        // ไม่งั้นรอบที่จบโดยไม่มีร่างก็กินโควตาร่างรายวันไปจนถึงเที่ยงคืน
        if ($log->draft_reserved_at !== null && $log->drafted_at === null) {
            $this->reservations->releaseDraft($log, (string) $log->claim_token, $now);
        }

        // ---- ขั้น 2: recheck (D34) ----
        $reason = FollowUpEligibility::recheck($log, $now);
        if ($reason !== null) {
            if (in_array($reason, self::CANCEL_REASONS, true)) {
                $this->write($logId, [
                    'delivery_status' => LeadRecoveryLog::DELIVERY_STATUS_CANCELLED,
                    'reason_code' => $reason,
                    'updated_at' => $nowStr,
                ]);
            } else {
                // เหตุชั่วคราว — กลับสถานะที่ยืมมา (sorting/drafting กลับเป็น candidate)
                $backStatus = in_array($claimedFrom, [
                    LeadRecoveryLog::DELIVERY_STATUS_SORTING,
                    LeadRecoveryLog::DELIVERY_STATUS_DRAFTING,
                ], true) ? LeadRecoveryLog::DELIVERY_STATUS_CANDIDATE : $claimedFrom;
                $this->write($logId, [
                    'delivery_status' => $backStatus,
                    'reason_code' => $reason,
                    'updated_at' => $nowStr,
                ]);
            }

            return;
        }

        // ---- ขั้น 3: context (D31) ----
        $lines = $this->contextLines($log);
        if ($lines === null) {
            // ข้อมูลบัญชีในบริบท — ตัดจำพวกตามหลังขาย กลุ่มยังไม่ถูกคิด (D35)
            $this->write($logId, [
                'delivery_status' => LeadRecoveryLog::DELIVERY_STATUS_EXCLUDED,
                'reason_code' => 'sensitive_account_data',
                'updated_at' => $nowStr,
            ]);

            return;
        }
        if ($lines === []) {
            $this->write($logId, [
                'delivery_status' => LeadRecoveryLog::DELIVERY_STATUS_EXCLUDED,
                'reason_code' => 'no_context',
                'updated_at' => $nowStr,
            ]);

            return;
        }
        $this->write($logId, [
            'context_redacted' => json_encode($lines, JSON_UNESCAPED_UNICODE),
            'updated_at' => $nowStr,
        ]);

        // ---- ขั้น 4: sort ----
        $signal = DB::table('follow_up_sales_signals')
            ->where('conversation_id', $before->conversation_id)
            ->first(['last_message_at', 'latest_order_id']);
        $boughtBefore = $signal !== null && $signal->latest_order_id !== null;
        $sort = $this->decisions->sort($lines, $boughtBefore, (bool) $before->transfer_summary_sent);
        if ($sort === null) {
            // sort ล้ม — ไม่มีขั้นไหนรายงาน cost/usage ของรอบนี้ แต่ค่าสะสมเดิมต้องคงอยู่
            $this->failRow($logId, self::DRAFT_FAILURE_REASON_SORT, $now, $earlierCost);

            return;
        }

        $group = $sort['group'];
        $sortWrites = [
            'group_code' => $group,
            'group_confidence' => $sort['confidence'],
            'declined_score' => $sort['declined'],
            'decision_version' => FollowUpPrompts::SORT_VERSION,
            'sort_answers' => json_encode([
                'group' => ['choice' => $group, 'confidence' => $sort['confidence']],
                'declined' => ['noul' => $sort['declined']],
            ], JSON_UNESCAPED_UNICODE),
            'sort_latency_ms' => $sort['latency_ms'],
        ];

        // กฎกลุ่มมาก่อน declined (การ์ด B3c ขั้น 4)
        $stopReason = null;
        if ($group === LeadRecoveryLog::GROUP_CODE_G1) {
            $stopReason = 'group_g1';
        } elseif ($group === LeadRecoveryLog::GROUP_CODE_G4) {
            $stopReason = 'group_g4';
        } elseif ($sort['declined'] >= (float) config('customer_follow_up.declined_threshold')) {
            $stopReason = 'declined';
        }

        $sortCosts = [$sort['cost']];
        $sortUsage = ['sort' => $sort['usage']];

        // เก็บคะแนนทันทีตามขั้น 4 — ไม่ว่าโค้ดชีวิตจะจบด้วยสถานะใด
        $this->write($logId, $sortWrites + [
            'updated_at' => $nowStr,
        ] + $this->costWrites($earlierCost, $sortCosts, $sortUsage));

        if ($stopReason !== null) {
            $this->write($logId, [
                'delivery_status' => LeadRecoveryLog::DELIVERY_STATUS_EXCLUDED,
                'reason_code' => $stopReason,
                'updated_at' => $nowStr,
            ]);

            return;
        }

        // ไทม์มิ่งฝั่งชำระ (plan line 151): ห้องจ่ายเงิน + G3 + เงียบยังไม่ครบกรอบขาย = หน่วง
        $saleWaitHours = (int) config('customer_follow_up.sale_wait_hours');
        $lastMessageAt = $signal !== null && $signal->last_message_at !== null
            ? CarbonImmutable::parse((string) $signal->last_message_at, FollowUpCapabilities::TIMEZONE)
            : null;
        if ((bool) $before->payment_step
            && $group === LeadRecoveryLog::GROUP_CODE_G3
            && $lastMessageAt !== null
            && $lastMessageAt->gt($now->subHours($saleWaitHours))) {
            $this->write($logId, [
                'delivery_status' => LeadRecoveryLog::DELIVERY_STATUS_DEFERRED,
                'reason_code' => 'wait_for_sale_window',
                'next_attempt_at' => $lastMessageAt->addHours($saleWaitHours)->format('Y-m-d H:i:s'),
                'updated_at' => $nowStr,
            ]);

            return;
        }

        // ---- ขั้น 5: reserve (D36) ----
        if (! $this->reservations->reserveDraft($log, $now)) {
            $this->write($logId, [
                'delivery_status' => LeadRecoveryLog::DELIVERY_STATUS_DEFERRED,
                'reason_code' => 'daily_cap',
                'next_attempt_at' => $now->addDay()->startOfDay()->addHours(8)->format('Y-m-d H:i:s'),
                'updated_at' => $nowStr,
            ] + $this->costWrites($earlierCost, $sortCosts, $sortUsage));

            return;
        }
        $token = (string) DB::table('lead_recovery_logs')->where('id', $logId)->value('claim_token');
        $this->write($logId, [
            'delivery_status' => LeadRecoveryLog::DELIVERY_STATUS_DRAFTING,
            'updated_at' => $nowStr,
        ]);

        // ---- ขั้น 6: draft ----
        $draft = $this->drafts->draft($log->bot, $lines, $group);
        if ($draft === null) {
            $this->reservations->releaseDraft($log, $token, $now);
            $this->failRow($logId, self::DRAFT_FAILURE_REASON_DRAFT, $now, $earlierCost, $sortCosts, $sortUsage);

            return;
        }

        // ---- ขั้น 7: check แล้วบันทึกทั้งหมดใน transaction เดียว (D35) ----
        $check = $this->decisions->check($lines, $group, $draft['text']);
        $checkAnswers = [
            'scores' => $check['scores'],
            'failed' => $check['failed'],
            'error' => $check['error'],
            'version' => FollowUpPrompts::CHECK_VERSION,
        ];
        $runCosts = [$sort['cost'], $draft['cost'], $check['cost']];
        $usageSteps = ['sort' => $sort['usage'], 'draft' => $draft['usage'], 'check' => $check['usage']];
        $expiry = $now->addHours((int) config('customer_follow_up.approval_ttl_hours'))->format('Y-m-d H:i:s');

        try {
            DB::transaction(function () use ($logId, $log, $token, $now, $explicitNow, $draft, $check, $checkAnswers, $earlierCost, $runCosts, $usageSteps, $expiry, $nowStr): void {
                if (! $this->reservations->markDraftProduced($log, $token, $now)) {
                    // แถวโดนเปลี่ยน claim_token ระหว่าง draft กับ persist — ยกเลิกทุกการเขียน
                    throw new RuntimeException('follow_up_claim_lost');
                }
                $this->write($logId, [
                    'draft' => $draft['text'],
                    'draft_model' => $draft['model'],
                    'draft_latency_ms' => $draft['latency_ms'],
                    'check_latency_ms' => $check['latency_ms'],
                    'check_answers' => json_encode($checkAnswers, JSON_UNESCAPED_UNICODE),
                    'model_cost' => $this->modelCost($earlierCost, $runCosts),
                    'usage_totals' => json_encode($this->usageTotals($usageSteps), JSON_UNESCAPED_UNICODE),
                    'reason_code' => null,
                    'delivery_status' => LeadRecoveryLog::DELIVERY_STATUS_PENDING,
                    'updated_at' => $nowStr,
                ]);
                $approvalId = (int) DB::table('follow_up_approvals')->insertGetId([
                    'lead_recovery_log_id' => $logId,
                    'status' => FollowUpApproval::STATUS_PENDING,
                    'revision' => 1,
                    'nonce' => bin2hex(random_bytes(8)),
                    'text' => $draft['text'],
                    'check_answers' => json_encode($checkAnswers, JSON_UNESCAPED_UNICODE),
                    'check_overridden' => false,
                    'expires_at' => $expiry,
                    'telegram_delivery_state' => FollowUpApproval::TELEGRAM_STATE_NEW,
                    // D38: เวลาของแถวอนุมัติ = สตริงกรุงเทพฯ เดียวกับ updated_at ของ log รอบนี้
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ]);

                // B3a: ผู้ตัดสินส่งเอง — สาขาเดียวในระบบที่เขียน owner_action = automatic
                // (A4/A15/A17). ตัดสาขาก่อนเรียกกฎ: host list ว่างหรือ mode approve = เขียน
                // เหมือน main เป๊ะ ไม่มีคำสั่งเพิ่ม ไม่มี reason ใหม่ กฎอ่าน database เท่านั้น
                // (ไม่มี HTTP/โควต้าใน transaction) และตัดสินจากแถวที่เพิ่งบันทึกใน transaction เดียวกัน
                if ($this->automaticCandidate((int) $log->bot_id)) {
                    // นาฬิกาตัดสิน — ผู้เรียกส่งมาเอง (เทส inject เวลา) ใช้ค่านั้น, ไม่ส่ง =
                    // อ่าน "ณ บรรทัดนี้" หลัง sort/draft/check กินเวลาไปแล้ว ไม่ใช่นาฬิกาเริ่ม prepare
                    $decisionNow = ($explicitNow ?? CarbonImmutable::now())
                        ->tz(FollowUpCapabilities::TIMEZONE)
                        ->startOfSecond();
                    $decisionNowStr = $decisionNow->format('Y-m-d H:i:s');
                    $autoLog = LeadRecoveryLog::query()->findOrFail($logId);
                    $autoApproval = DB::table('follow_up_approvals')->where('id', $approvalId)->first();
                    $autoReason = $this->autoRule->reason($autoLog, $autoApproval, $decisionNow);

                    if ($autoReason === null) {
                        $this->write($logId, [
                            'delivery_status' => LeadRecoveryLog::DELIVERY_STATUS_APPROVED,
                            'owner_action' => LeadRecoveryLog::OWNER_ACTION_AUTOMATIC,
                            'owner_action_at' => $decisionNowStr,
                            'owner_telegram_user_id' => null,
                            'updated_at' => $decisionNowStr,
                        ]);
                        DB::table('follow_up_approvals')
                            ->where('id', $approvalId)
                            ->update(['status' => FollowUpApproval::STATUS_APPROVED, 'updated_at' => $decisionNowStr]);
                    } else {
                        $this->write($logId, ['reason_code' => $autoReason, 'updated_at' => $nowStr]);
                    }
                }
            });
        } catch (RuntimeException $claimLost) {
            // เฉพาะ marker ของเราเท่านั้น — database error ต้องยิงต่อให้ job ล้มเอง
            if ($claimLost->getMessage() !== 'follow_up_claim_lost') {
                throw $claimLost;
            }
            Log::warning('Follow-up pipeline claim lost', [
                'log_id' => $logId,
                'step' => 'persist',
                'reason' => 'claim_lost',
            ]);
        }
    }

    /**
     * ตัดสาขา "พยายามส่งเองไหม" ก่อนเรียกกฎ (A2/A12): host list ว่าง = ทุกบอทห้ามส่งเอง
     * และ mode ต้องเป็น automatic จากฐานข้อมูลล่าสุดเท่านั้น อ่าน database + config เท่านั้น
     */
    private function automaticCandidate(int $botId): bool
    {
        $hostList = array_map(intval(...), (array) config('customer_follow_up.automatic_bot_ids', []));
        if ($hostList === [] || ! in_array($botId, $hostList, true)) {
            return false;
        }

        return DB::table('bot_settings')->where('bot_id', $botId)->value('follow_up_mode') === 'automatic';
    }

    /**
     * บริบทร่าง (D31) — ข้อความ id <= observed_last_message_id และ created_at >=
     * sales_started_at, ใหม่ 10 สุดตาม id แล้วเรียงเก่าก่อน แล้วผ่าน redactor
     * คืน snapshot ตามที่จะยิงจริง: null = เจอข้อมูลบัญชี, [] = ไม่มีบรรทัดใช้ได้
     *
     * @return list<string>|null
     */
    public function contextLines(LeadRecoveryLog $log): ?array
    {
        $salesStartedAt = DB::table('lead_recovery_logs')
            ->where('id', $log->id)
            ->value('sales_started_at');
        if (! is_string($salesStartedAt) || $salesStartedAt === '') {
            return [];
        }

        $messages = DB::table('messages')
            ->where('conversation_id', $log->conversation_id)
            ->where('id', '<=', (int) $log->observed_last_message_id)
            ->where('created_at', '>=', $salesStartedAt)
            ->orderByDesc('id')
            ->limit(FollowUpRedactor::MAX_LINES)
            ->get(['sender', 'type', 'content'])
            ->reverse()
            ->values()
            ->map(static fn (object $message): array => [
                'sender' => $message->sender,
                'type' => $message->type,
                'content' => $message->content,
            ])
            ->all();

        return FollowUpRedactor::snapshot($messages);
    }

    /** กฎความล้มเหลว (D33): +1 ครั้ง — ยังไม่ครบ 3 = sort_retry เว้น 30 นาที, ครบ = excluded */
    private function failRow(
        int $logId,
        string $reason,
        CarbonImmutable $now,
        ?float $earlierCost = null,
        array $runCosts = [],
        array $usageSteps = [],
    ): void {
        $row = DB::table('lead_recovery_logs')->where('id', $logId)->first(['attempt_count']);
        $count = (int) ($row->attempt_count ?? 0) + 1;
        $writes = [
            'attempt_count' => $count,
            'reason_code' => $reason,
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ];
        if ($count < self::MAX_ATTEMPTS) {
            $writes['delivery_status'] = LeadRecoveryLog::DELIVERY_STATUS_SORT_RETRY;
            $writes['next_attempt_at'] = $now->addMinutes(self::RETRY_MINUTES)->format('Y-m-d H:i:s');
        } else {
            $writes['delivery_status'] = LeadRecoveryLog::DELIVERY_STATUS_EXCLUDED;
        }
        $this->write($logId, $writes + $this->costWrites($earlierCost, $runCosts, $usageSteps));
    }

    /**
     * ค่าใช้จ่ายรวม (D31 เสริม): ค่าโทรครั้งนี้ที่ไม่ null + ค่าเดิมของแถว;
     * null เมื่อทุกค่าจนถึงตอนนี้เป็น null; usage_totals เล่ารอบล่าสุดที่มีอย่างน้อย
     * หนึ่งขั้นรายงาน — รอบที่ไม่มีขั้นไหนรายงานเลย คงค่าเดิมในแถวไว้ (ไม่เขียนทับ)
     *
     * @param  list<?float>  $runCosts
     * @param  array<string, array<string, mixed>>  $usageSteps
     * @return array<string, mixed>
     */
    private function costWrites(?float $earlierCost, array $runCosts, array $usageSteps): array
    {
        $writes = ['model_cost' => $this->modelCost($earlierCost, $runCosts)];
        $usage = $this->usageTotals($usageSteps);
        if ($usage !== []) {
            $writes['usage_totals'] = json_encode($usage, JSON_UNESCAPED_UNICODE);
        }

        return $writes;
    }

    /**
     * @param  list<?float>  $runCosts
     */
    private function modelCost(?float $earlierCost, array $runCosts): ?float
    {
        $present = array_values(array_filter(
            [$earlierCost, ...$runCosts],
            static fn (?float $cost): bool => $cost !== null
        ));

        return $present === [] ? null : array_sum($present);
    }

    /**
     * เลขอย่างเดียว — cost ที่เป็น null ถูกทิ้ง ขั้นที่ไม่มี usage ไม่มี key;
     * ขั้น draft ได้ usage ดิบจาก OpenRouterService::parseResponse (prompt_tokens/
     * completion_tokens) — แปลงเป็นชื่อเดียวกับ Decisions ที่ประตูนี้ ไม่คัดลอก key อื่น
     *
     * @param  array<string, array<string, mixed>>  $usageSteps
     * @return array<string, array<string, int|float>>
     */
    private function usageTotals(array $usageSteps): array
    {
        $totals = [];
        foreach ($usageSteps as $step => $usage) {
            if (! is_array($usage)) {
                continue;
            }
            $input = $usage['input_tokens'] ?? ($step === 'draft' ? ($usage['prompt_tokens'] ?? null) : null);
            $output = $usage['output_tokens'] ?? ($step === 'draft' ? ($usage['completion_tokens'] ?? null) : null);
            $entry = [
                'input_tokens' => is_numeric($input) ? (int) $input : 0,
                'output_tokens' => is_numeric($output) ? (int) $output : 0,
            ];
            if (isset($usage['cost']) && is_numeric($usage['cost'])) {
                $entry['cost'] = (float) $usage['cost'];
            }
            $totals[$step] = $entry;
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function write(int $logId, array $values): void
    {
        DB::table('lead_recovery_logs')->where('id', $logId)->update($values);
    }
}
