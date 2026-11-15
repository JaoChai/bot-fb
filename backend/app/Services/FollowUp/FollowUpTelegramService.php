<?php

namespace App\Services\FollowUp;

use App\Models\Bot;
use App\Models\FlowPlugin;
use App\Models\FollowUpApproval;
use App\Services\Payment\TelegramAlertBotService;
use App\Support\FollowUpRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ผู้เผยแพร่บัตร Telegram ใบแรกของ "ตามลูกค้า" (การ์ด B6a — D69-D74)
 *
 * กติกาสูงสุด (D70 / D9): บัตรหนึ่งใบมี sendMessage ได้ "ครั้งเดียวตลอดชีวิต"
 * - claim new -> sending ด้วย UPDATE แบบมีเงื่อนไขหนึ่งครั้ง (ใครชนะได้ส่ง)
 * - final write อีกครั้งเดียวจบ (sending -> sent/failed/unknown); แพ้สิทธิ์ = ไม่เขียนอะไรเลย
 * - การ์ดที่ชะตายังไม่รู้ (unknown) ห้ามส่งซ้ำเด็ดขาด ไม่มี retry อัตโนมัติ
 *
 * เขียนได้เฉพาะคอลัมน์ telegram_* และ updated_at ของ follow_up_approvals (D72)
 * สถานะ approval ยังเป็น pending ตามเดิม — คนตัดสินด้วยปุ่ม (การ์ด B6b) เท่านั้น
 * ไม่มี caller ในแอปยัง: มีแต่ test เรียก การเดินเครื่องจริงเป็นของ B6c
 */
class FollowUpTelegramService
{
    /** วินาทีที่ claim sending ถือว่าตาย (D70) */
    private const CLAIM_STALE_SECONDS = 120;

    /** บรรทัดสถานะบนการ์ด (B6b) — สำเนาตรงจาก b6b-strings.json (sha256 9e1f610e…d457) */
    private const STATUS_LINES = [
        'approved' => '⏳ กดส่งแล้ว กำลังส่งให้ลูกค้า',
        'sending' => '⏳ กดส่งแล้ว กำลังส่งให้ลูกค้า',
        'sent' => '✅ ส่งให้ลูกค้าแล้ว',
        'skipped' => '⏭ ข้ามแล้ว ไม่ได้ส่ง',
        'expired' => '⌛ หมดอายุ ไม่ได้ส่ง',
        'cancelled' => '🚫 ยกเลิก ไม่ได้ส่ง',
    ];

    /** ยิงส่งแล้วแต่ยืนยันผลไม่ได้ — ต่างจากยกเลิกธรรมดา (D80) */
    private const STATUS_ACCEPTANCE_UNKNOWN = '⚠️ ส่งแล้วแต่ยืนยันผลไม่ได้ โปรดเปิดห้องตรวจ';

    /** เหตุยกเลิกที่โชว์บนการ์ด — line_rejected_<status> พับเป็น line_rejected */
    private const CANCEL_REASONS = [
        'new_customer_message' => 'ลูกค้าพิมพ์เข้ามาแล้ว',
        'message_arrived' => 'ลูกค้าพิมพ์เข้ามาแล้ว',
        'new_bot_message' => 'บอทตอบในห้องนี้ไปแล้ว',
        'order_created' => 'ลูกค้าสั่งซื้อแล้ว',
        'passed_slip' => 'ลูกค้าสั่งซื้อแล้ว',
        'unsafe_text' => 'ข้อความมีสิ่งที่ส่งไม่ได้',
        'recipient_invalid' => 'ส่งถึงลูกค้ารายนี้ไม่ได้',
        'line_rejected' => 'LINE ไม่รับข้อความ',
    ];

    private const CANCEL_REASON_SUFFIX = ' ({reason})';

    /** ตัดค่าก่อน escape (D73): ชื่อ/บอท 40 จุดโค้ด, preview 120 จุดโค้ด — ร่างข้อความส่งทั้งฉบับ */
    private const NAME_MAX_CODE_POINTS = 40;

    private const PREVIEW_MAX_CODE_POINTS = 120;

    /** ถ้อยคำบนบัตร — สำเนาตรงจาก b6a-strings.json (sha256 1cb94314…37fa, Lead 15 พ.ย.) */
    private const STRINGS = [
        'title' => '🔔 <b>ตามลูกค้าที่เงียบไป</b> · #{log_id}',
        'bot_line' => 'บอท: {bot_name}',
        'customer_line' => 'ลูกค้า: {customer}',
        'customer_fallback' => 'ลูกค้า #{conversation_id}',
        'group_line' => 'กลุ่ม: {group_label}',
        'group_confidence_suffix' => ' · Luna มั่นใจ {percent}%',
        'group_labels' => [
            'G2' => 'ค้างที่ขั้นจ่ายเงิน',
            'G3' => 'สนใจแต่ยังลังเล',
        ],
        'group_label_other' => 'ไม่ระบุ',
        'last_customer_line' => 'ลูกค้าพิมพ์ล่าสุด: {preview}',
        'last_bot_line' => 'บอทตอบล่าสุด: {preview}',
        'preview_empty' => '-',
        'preview_non_text' => '(ไม่ใช่ข้อความ)',
        'preview_cut_mark' => '…',
        'draft_heading' => '<b>ร่างข้อความ</b>',
        'check_line' => 'ตรวจข้อความ: {result}',
        'check_pass' => '✅ ผ่าน',
        'check_warn' => '⚠️ เตือน: {labels}',
        'check_warn_joiner' => ' · ',
        'check_unreadable' => '⚠️ ตรวจไม่ได้',
        'check_labels' => [
            'support_free' => 'บอกว่า Support ฟรี',
            'promise' => 'สัญญาผลหรือเวลา',
            'cannot_self' => 'บอกว่าลูกค้าทำเองไม่ได้',
            'price_stock' => 'พูดถึงราคาหรือสต็อก',
            'pressure' => 'เร่งหรือกดดันลูกค้า',
            'off_topic' => 'ไม่ตรงเรื่องที่คุยค้างไว้',
        ],
        'flag_note' => 'เจ้านายยังกดส่งได้ แต่ข้อความนี้มีคำเตือน',
        'expiry_line' => 'หมดอายุ: {dd}/{mm} {HH}:{MM} ({ttl_hours} ชั่วโมง)',
        'room_line' => 'เปิดห้อง: {url}',
        'buttons' => [
            ['fs', 'ส่ง'],
            ['fk', 'ข้าม'],
            ['fe', 'แก้ข้อความ'],
        ],
    ];

    public function __construct(private readonly TelegramAlertBotService $telegram) {}

    /**
     * เผยแพร่บัตร Telegram ของ approval หนึ่งแถว
     * คืน sent | already_sent | failed | unknown | busy | blocked
     */
    public function publish(int $approvalId, ?CarbonImmutable $now = null): string
    {
        $now = ($now ?? CarbonImmutable::now(FollowUpCapabilities::TIMEZONE))
            ->tz(FollowUpCapabilities::TIMEZONE)
            ->startOfSecond();
        $nowStr = $now->format('Y-m-d H:i:s');

        $approval = DB::table('follow_up_approvals')->where('id', $approvalId)->first();
        if ($approval === null) {
            return $this->finish($approvalId, null, null, 'blocked', 'missing_approval', null);
        }

        $log = DB::table('lead_recovery_logs')->where('id', $approval->lead_recovery_log_id)->first();
        if ($log === null) {
            return $this->finish($approvalId, (int) $approval->lead_recovery_log_id, null, 'blocked', 'missing_log', null);
        }

        $logId = (int) $log->id;
        $botId = (int) $log->bot_id;

        $state = (string) $approval->telegram_delivery_state;
        if ($state !== FollowUpApproval::TELEGRAM_STATE_NEW) {
            return $this->answerForNonNewState($approvalId, $logId, $botId, $approval, $state, $now, $nowStr);
        }

        if ((string) $approval->status !== FollowUpApproval::STATUS_PENDING) {
            return $this->finish($approvalId, $logId, $botId, 'blocked', 'approval_status', null);
        }

        if ((string) $log->delivery_status !== 'pending') {
            return $this->finish($approvalId, $logId, $botId, 'blocked', 'log_status', null);
        }

        if ($nowStr >= (string) $approval->expires_at) {
            return $this->finish($approvalId, $logId, $botId, 'blocked', 'expired', null);
        }

        $bot = Bot::query()->find($botId);
        if ($bot === null) {
            return $this->finish($approvalId, $logId, $botId, 'blocked', 'bot_missing', null);
        }

        $blockedReason = FollowUpCapabilities::forBot($bot, $now)['blocked_reason'];
        if ($blockedReason !== null) {
            return $this->finish($approvalId, $logId, $botId, 'blocked', (string) $blockedReason, null);
        }

        $plugin = FollowUpCapabilities::alertPlugin($bot);
        if ($plugin === null) {
            return $this->finish($approvalId, $logId, $botId, 'blocked', 'telegram_missing', null);
        }
        $chatId = (string) ($plugin->config['chat_id'] ?? '');

        // D70: สิทธิ์ส่งยึดด้วย UPDATE มีเงื่อนไขครั้งเดียว — แถวเปลี่ยนไปแล้ว = คนอื่นทำงานอยู่
        $claimed = DB::table('follow_up_approvals')
            ->where('id', $approvalId)
            ->where('telegram_delivery_state', FollowUpApproval::TELEGRAM_STATE_NEW)
            ->where('status', FollowUpApproval::STATUS_PENDING)
            ->update([
                'telegram_delivery_state' => FollowUpApproval::TELEGRAM_STATE_SENDING,
                'telegram_plugin_id' => $plugin->id,
                'telegram_chat_id' => $chatId,
                'updated_at' => $nowStr,
            ]) === 1;
        if (! $claimed) {
            return $this->finish($approvalId, $logId, $botId, 'busy', 'telegram_claim_refused', null);
        }

        [$html, $keyboard] = $this->renderCard($approval, $log, $bot);
        $outcome = $this->telegram->sendFollowUpCard(
            (string) ($plugin->config['access_token'] ?? ''),
            $chatId,
            $html,
            $keyboard,
        );

        $newState = match ($outcome['outcome']) {
            'accepted' => FollowUpApproval::TELEGRAM_STATE_SENT,
            'rejected' => FollowUpApproval::TELEGRAM_STATE_FAILED,
            default => FollowUpApproval::TELEGRAM_STATE_UNKNOWN,
        };

        $updates = [
            'telegram_delivery_state' => $newState,
            'updated_at' => $nowStr,
        ];
        if ($newState === FollowUpApproval::TELEGRAM_STATE_SENT) {
            $updates['telegram_message_id'] = $outcome['message_id'];
        }

        // final write ครั้งเดียว: แพ้สิทธิ์ = นักเขียนคนอื่นยึดระหว่าง request — ไม่แตะค่าของเขา
        $written = DB::table('follow_up_approvals')
            ->where('id', $approvalId)
            ->where('telegram_delivery_state', FollowUpApproval::TELEGRAM_STATE_SENDING)
            ->update($updates) === 1;
        if (! $written) {
            return $this->finish($approvalId, $logId, $botId, 'blocked', 'claim_lost', null);
        }

        $result = match ($newState) {
            FollowUpApproval::TELEGRAM_STATE_SENT => 'sent',
            FollowUpApproval::TELEGRAM_STATE_FAILED => 'failed',
            default => 'unknown',
        };

        return $this->finish($approvalId, $logId, $botId, $result, null, $outcome['status']);
    }

    /**
     * เขียนการ์ดใหม่ด้วยบรรทัดสถานะ (B6b) — ตามสถานะปลายทางที่ผู้ส่งเขียนไว้แล้ว
     * เขียนฐานข้อมูลไม่ลงเลย (อ่านอย่างเดียว) ยิง editMessageText หนึ่งครั้งเมื่อต้องแก้
     * คืน refreshed|failed|unknown|unchanged|blocked
     */
    public function refreshCard(int $approvalId, ?CarbonImmutable $now = null): string
    {
        $approval = DB::table('follow_up_approvals')->where('id', $approvalId)->first();
        if ($approval === null) {
            return 'blocked';
        }

        $log = DB::table('lead_recovery_logs')->where('id', $approval->lead_recovery_log_id)->first();
        if ($log === null) {
            return 'blocked';
        }

        $bot = Bot::query()->find((int) $log->bot_id);
        if ($bot === null) {
            return 'blocked';
        }

        $state = (string) $approval->telegram_delivery_state;
        if ($state !== FollowUpApproval::TELEGRAM_STATE_SENT || $approval->telegram_message_id === null) {
            return 'blocked';
        }

        $plugin = FlowPlugin::query()->find((int) $approval->telegram_plugin_id);
        if ($plugin === null || (string) $plugin->type !== 'telegram' || ! (bool) $plugin->enabled) {
            return 'blocked';
        }
        $token = (string) ($plugin->config['access_token'] ?? '');
        if ($token === '' || (string) ($plugin->config['chat_id'] ?? '') !== (string) $approval->telegram_chat_id) {
            return 'blocked';
        }

        $status = (string) $approval->status;
        if ($status === FollowUpApproval::STATUS_PENDING || $status === FollowUpApproval::STATUS_EDITING) {
            return 'unchanged';
        }

        $line = $status === FollowUpApproval::STATUS_CANCELLED
            ? $this->cancelledStatusLine($log)
            : self::STATUS_LINES[$status] ?? null;
        if ($line === null) {
            return 'blocked';
        }

        [$html] = $this->renderCard($approval, $log, $bot);

        $outcome = $this->telegram->editFollowUpCard(
            $token,
            (string) $approval->telegram_chat_id,
            (int) $approval->telegram_message_id,
            $html."\n\n".$line,
            [],
        );

        return match ($outcome['outcome']) {
            'accepted' => 'refreshed',
            'rejected' => 'failed',
            default => 'unknown',
        };
    }

    /** บรรทัดสถานะของ cancelled — acceptance_unknown ต้องต่างจาก "ยกเลิก" ธรรมดา */
    private function cancelledStatusLine(object $log): string
    {
        $reasonCode = (string) ($log->reason_code ?? '');

        if ((string) $log->delivery_status === 'send_failed'
            && str_starts_with($reasonCode, 'acceptance_unknown')) {
            return self::STATUS_ACCEPTANCE_UNKNOWN;
        }

        $line = self::STATUS_LINES[FollowUpApproval::STATUS_CANCELLED];
        $key = $reasonCode;
        foreach (self::CANCEL_REASONS as $prefix => $text) {
            if ($reasonCode === $prefix || ($prefix === 'line_rejected' && str_starts_with($reasonCode, 'line_rejected_'))) {
                $key = $prefix;
                break;
            }
        }

        if (! isset(self::CANCEL_REASONS[$key])) {
            return $line;
        }

        return $line.str_replace('{reason}', self::CANCEL_REASONS[$key], self::CANCEL_REASON_SUFFIX);
    }

    /** ตอบจาก telegram_delivery_state ที่ไม่ใช่ new — ไม่ยิง HTTP ยกเว้น claim sending ตาย 120 วิ */
    private function answerForNonNewState(int $approvalId, int $logId, int $botId, object $approval, string $state, CarbonImmutable $now, string $nowStr): string
    {
        switch ($state) {
            case FollowUpApproval::TELEGRAM_STATE_SENT:
                return $this->finish($approvalId, $logId, $botId, 'already_sent', null, null);
            case FollowUpApproval::TELEGRAM_STATE_FAILED:
                return $this->finish($approvalId, $logId, $botId, 'failed', null, null);
            case FollowUpApproval::TELEGRAM_STATE_UNKNOWN:
                return $this->finish($approvalId, $logId, $botId, 'unknown', null, null);
            case FollowUpApproval::TELEGRAM_STATE_SENDING:
                $staleCutoff = $now->subSeconds(self::CLAIM_STALE_SECONDS)->format('Y-m-d H:i:s');
                if ((string) $approval->updated_at > $staleCutoff) {
                    return $this->finish($approvalId, $logId, $botId, 'busy', 'telegram_claim_active', null);
                }

                // claim ตาย — ทำเครื่องหมาย unknown ด้วย UPDATE มีเงื่อนไขหนึ่งครั้ง
                // ห้ามส่งซ้ำ (D70): unknown รอคนตัดสินและ scheduler ไม่แตะ
                DB::table('follow_up_approvals')
                    ->where('id', $approvalId)
                    ->where('telegram_delivery_state', FollowUpApproval::TELEGRAM_STATE_SENDING)
                    ->update([
                        'telegram_delivery_state' => FollowUpApproval::TELEGRAM_STATE_UNKNOWN,
                        'updated_at' => $nowStr,
                    ]);

                return $this->finish($approvalId, $logId, $botId, 'unknown', 'telegram_claim_stale', null);
            default:
                // ค่านอกชุดคงที่ของโมเดล = ข้อมูลเสีย — ปิดทางส่งกันพลาด
                return $this->finish($approvalId, $logId, $botId, 'blocked', 'telegram_state_unexpected', null);
        }
    }

    /** log บรรทัดเดียวต่อการ publish แล้วคืนผลลัพธ์ */
    private function finish(int $approvalId, ?int $logId, ?int $botId, string $result, ?string $reason, ?int $httpStatus): string
    {
        Log::info('Follow-up card publish', [
            'approval_id' => $approvalId,
            'log_id' => $logId,
            'bot_id' => $botId,
            'result' => $result,
            'reason' => $reason,
            'http_status' => $httpStatus,
        ]);

        return $result;
    }

    /**
     * ประกอบข้อความบัตร + คีย์บอร์ดสามปุ่ม (D73)
     * ค่า dynamic ทุกตัว ตัดก่อนแล้ว escape — ร่างข้อความส่งทั้งฉบับไม่ตัด
     *
     * @return array{0: string, 1: array}
     */
    private function renderCard(object $approval, object $log, Bot $bot): array
    {
        $s = self::STRINGS;
        $customer = $this->customerValue($log);

        $lines = [];
        $lines[] = str_replace('{log_id}', TelegramAlertBotService::esc((string) $log->id), $s['title']);
        $lines[] = str_replace(
            '{bot_name}',
            TelegramAlertBotService::esc(self::cut((string) $bot->name, self::NAME_MAX_CODE_POINTS)),
            $s['bot_line'],
        );
        $lines[] = $customer === null
            ? str_replace('{conversation_id}', TelegramAlertBotService::esc((string) $log->conversation_id), $s['customer_fallback'])
            : str_replace('{customer}', TelegramAlertBotService::esc($customer), $s['customer_line']);

        $label = $s['group_labels'][(string) $log->group_code] ?? $s['group_label_other'];
        $groupLine = str_replace('{group_label}', TelegramAlertBotService::esc($label), $s['group_line']);
        if ($log->group_confidence !== null) {
            $percent = intdiv((int) round((float) $log->group_confidence * 10000), 100);
            $groupLine .= str_replace('{percent}', TelegramAlertBotService::esc((string) $percent), $s['group_confidence_suffix']);
        }
        $lines[] = $groupLine;

        $lines[] = str_replace(
            '{preview}',
            TelegramAlertBotService::esc($this->previewText($log->context_redacted, 'C:')),
            $s['last_customer_line'],
        );
        $lines[] = str_replace(
            '{preview}',
            TelegramAlertBotService::esc($this->previewText($log->context_redacted, 'B:')),
            $s['last_bot_line'],
        );
        $lines[] = '';
        $lines[] = $s['draft_heading'];
        $lines[] = TelegramAlertBotService::esc((string) $approval->text);
        $lines[] = '';

        [$checkResult, $flagged] = $this->checkResult($approval->check_answers);
        $lines[] = str_replace('{result}', TelegramAlertBotService::esc($checkResult), $s['check_line']);
        if ($flagged) {
            $lines[] = $s['flag_note'];
        }

        $expires = (string) $approval->expires_at;
        $lines[] = str_replace(
            ['{dd}', '{mm}', '{HH}', '{MM}', '{ttl_hours}'],
            [
                TelegramAlertBotService::esc(substr($expires, 8, 2)),
                TelegramAlertBotService::esc(substr($expires, 5, 2)),
                TelegramAlertBotService::esc(substr($expires, 11, 2)),
                TelegramAlertBotService::esc(substr($expires, 14, 2)),
                TelegramAlertBotService::esc((string) (int) config('customer_follow_up.approval_ttl_hours')),
            ],
            $s['expiry_line'],
        );

        $roomUrl = rtrim((string) config('customer_follow_up.admin_web_url'), '/')
            .'/chat?botId='.$bot->id.'&conversationId='.$log->conversation_id;
        $lines[] = str_replace('{url}', TelegramAlertBotService::esc($roomUrl), $s['room_line']);

        $buttons = [];
        foreach ($s['buttons'] as [$code, $buttonLabel]) {
            $buttons[] = [
                'text' => $buttonLabel,
                'callback_data' => $code.'|'.$approval->id.'|'.$approval->revision.'|'.$approval->nonce,
            ];
        }

        return [implode("\n", $lines), [$buttons]];
    }

    /**
     * ชื่อลูกค้า: display_name ของ profile ผ่าน redactor แล้วยุบช่องว่าง ตัด 40 จุดโค้ด (D73)
     * ว่าง/ขาด = null แล้วใช้บรรทัด fallback หมายเลขห้องแทน
     */
    private function customerValue(object $log): ?string
    {
        $profileId = DB::table('conversations')->where('id', $log->conversation_id)->value('customer_profile_id');
        $name = '';
        if ($profileId !== null) {
            $name = (string) (DB::table('customer_profiles')->where('id', $profileId)->value('display_name') ?? '');
        }

        $safe = FollowUpRedactor::redact($name);
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $safe));
        $cut = self::cut($collapsed, self::NAME_MAX_CODE_POINTS);

        return $cut !== '' ? $cut : null;
    }

    /**
     * preview จากบรรทัดท้ายสุดของ context_redacted ที่ขึ้นต้น prefix ('C:' ลูกค้า, 'B:' บอท)
     * context ไม่ใช่ JSON list ของ string ล้วน = '-' (fail closed, D73)
     */
    private function previewText(?string $rawContext, string $prefix): string
    {
        if ($rawContext === null) {
            return self::STRINGS['preview_empty'];
        }

        $list = json_decode($rawContext, true);
        if (! is_array($list) || ! array_is_list($list)) {
            return self::STRINGS['preview_empty'];
        }
        foreach ($list as $element) {
            if (! is_string($element)) {
                return self::STRINGS['preview_empty'];
            }
        }

        $found = null;
        foreach ($list as $line) {
            if (str_starts_with($line, $prefix)) {
                $found = $line;
            }
        }
        if ($found === null) {
            return self::STRINGS['preview_empty'];
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', substr($found, strlen($prefix))));
        if (preg_match('/^\[non-text:[a-z_]+\]$/', $text) === 1) {
            return self::STRINGS['preview_non_text'];
        }
        if ($text === '') {
            return self::STRINGS['preview_empty'];
        }
        if (mb_strlen($text, 'UTF-8') > self::PREVIEW_MAX_CODE_POINTS) {
            return mb_substr($text, 0, self::PREVIEW_MAX_CODE_POINTS, 'UTF-8').self::STRINGS['preview_cut_mark'];
        }

        return $text;
    }

    /**
     * บรรทัดตรวจข้อความ — กติกา "ไม่สะอาด" ตัวเดียวกับฝั่งส่ง: FollowUpSendService::checkFlagged (D53)
     * เตือนเป็นชื่อได้เมื่อ decode ได้ object, error === false เป๊ะ และ failed เป็น list ที่มี key ที่รู้จัก
     * นอกนั้นแสดง "ตรวจไม่ได้" — คืน [ข้อความผลตรวจ, สกปรกหรือไม่]
     *
     * @return array{0: string, 1: bool}
     */
    private function checkResult(?string $rawCheck): array
    {
        $raw = $rawCheck ?? '';
        if (! FollowUpSendService::checkFlagged($raw)) {
            return [self::STRINGS['check_pass'], false];
        }

        $answers = json_decode($raw);
        if (
            is_object($answers)
            && (($answers->error ?? null) === false)
            && is_array($answers->failed ?? null)
            && $answers->failed !== []
        ) {
            $labels = [];
            foreach (self::STRINGS['check_labels'] as $key => $label) {
                if (in_array($key, $answers->failed, true)) {
                    $labels[] = $label;
                }
            }
            if ($labels !== []) {
                return [
                    str_replace('{labels}', implode(self::STRINGS['check_warn_joiner'], $labels), self::STRINGS['check_warn']),
                    true,
                ];
            }
        }

        return [self::STRINGS['check_unreadable'], true];
    }

    /** ตัดตามจำนวนจุดโค้ด (ไม่ใช่ไบต์) — mb_* กับ UTF-8 */
    private static function cut(string $value, int $codePoints): string
    {
        return mb_strlen($value, 'UTF-8') > $codePoints
            ? mb_substr($value, 0, $codePoints, 'UTF-8')
            : $value;
    }
}
