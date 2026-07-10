<?php

namespace App\Services\Payment;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SlipVerification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlipVerificationService
{
    private const VERIFY_URL = 'https://api.easyslip.com/v2/verify/bank';

    // เคสที่ควรเตือนแรง 🚨 + ต้องกดยืนยัน 2 ชั้น (มีแนวโน้มโกงจริง)
    // amount_mismatch ไม่รวม — ยอดไม่ตรงมักเกิดจากบอทสรุปยอดผิด/จ่ายมัดจำ ไม่ใช่โกง จึงเตือนแบบ ⚠️ กดตรง
    private const FRAUD_REASONS = ['fake', 'duplicate', 'wrong_account'];

    private const FAIL_REASON_LABELS = [
        'fake' => 'ไม่พบธุรกรรมในระบบธนาคาร (อาจเป็นสลิปปลอม)',
        'pending' => 'สลิปกำลังรอธนาคารประมวลผล (โอนไม่ถึง 5 นาที) — รอสักครู่แล้วตรวจอีกครั้ง',
        'duplicate' => 'สลิปซ้ำ (เคยใช้ยืนยันไปแล้ว) — ระวังการนำสลิปเก่ามาใช้ซ้ำ',
        'amount_mismatch' => 'ยอดไม่ตรงกับออเดอร์',
        'wrong_account' => 'โอนเข้าบัญชีอื่น (ไม่ใช่บัญชีร้าน)',
        'no_pending_order' => 'ไม่พบออเดอร์ค้างชำระในบทสนทนา',
        'unreadable' => 'รูปสลิปอ่านไม่ได้/ไม่ชัด — ระบบตรวจอัตโนมัติไม่ได้ กรุณาตรวจมือ',
        'api_error' => 'ระบบตรวจสลิป (EasySlip) ใช้งานไม่ได้ชั่วคราว',
        'config_error' => 'ตั้งค่าไม่ครบ — EasySlip token หายไป กรุณาใส่ที่หน้า Settings (ระบบจะไม่ตรวจสลิปจนกว่าจะแก้)',
        'image_download_failed' => 'โหลดรูปจากลูกค้าไม่สำเร็จ — ระบบตรวจสลิปไม่ได้ กรุณาเปิดแชทดูรูป/ยอดเอง',
    ];

    public function __construct(
        private readonly PaymentMessageDetector $detector,
        private readonly TelegramAlertBotService $alertBot,
    ) {}

    /**
     * เทียบเลขบัญชีที่ตั้งค่าไว้ กับเลขบัญชี mask จาก EasySlip (เช่น "xxx-x-x4880-x").
     * กติกา: ตัดอักขระที่ไม่ใช่ตัวเลข/x ทั้งสองฝั่ง แล้วเทียบตำแหน่งจากท้าย
     * เฉพาะตำแหน่งที่ EasySlip เปิดเผยตัวเลข ต้องตรงทุกตัว และต้องมีตัวเลขเปิดเผยอย่างน้อย 1 ตัว
     */
    public static function accountMatches(string $configured, string $masked): bool
    {
        $configuredDigits = array_reverse(str_split(preg_replace('/\D/', '', $configured)));
        $maskedChars = array_reverse(str_split(preg_replace('/[^0-9xX]/', '', $masked)));

        if (count($maskedChars) === 0 || count($configuredDigits) < count($maskedChars)) {
            return false;
        }

        $visibleDigits = 0;
        foreach ($maskedChars as $i => $char) {
            if ($char === 'x' || $char === 'X') {
                continue;
            }
            $visibleDigits++;
            if ($configuredDigits[$i] !== $char) {
                return false;
            }
        }

        return $visibleDigits > 0;
    }

    /**
     * หาข้อความสรุปยอดโอนล่าสุดของบอทใน history แล้วคืนยอด + สรุปรายการ
     *
     * @param  array<int, array{sender: string, content: string}>  $conversationHistory
     * @return array{total: float, summary: string, items: array}|null
     */
    public function findExpectedPayment(array $conversationHistory, ?string $receiverAccount = null): ?array
    {
        foreach (array_reverse($conversationHistory) as $msg) {
            if (($msg['sender'] ?? '') !== 'bot') {
                continue;
            }
            $content = $msg['content'] ?? '';
            $qualifies = $this->detector->isPaymentMessage($content)
                || ($receiverAccount && str_contains($content, $receiverAccount)
                    && preg_match('/รวมยอดโอน|สรุปยอด|ยอดโอน|ยอดรวม|รวมเป็นเงิน|สรุปรายการ/u', $content));
            if (! $qualifies) {
                continue;
            }
            $data = $this->detector->parsePaymentData($content);
            if ($data === null) {
                continue;
            }

            $items = array_map(function (array $item) {
                $item['name'] = rtrim(trim($item['name']), '= ');

                return $item;
            }, $data['items']);
            $itemNames = array_column($items, 'name');

            return [
                'total' => (float) str_replace(',', '', $data['total']),
                'summary' => $itemNames === [] ? '-' : implode(', ', $itemNames),
                'items' => $items,
            ];
        }

        return null;
    }

    /**
     * @param  (\Closure(): ?bool)|null  $isSlipCheck  ตัวช่วยตัดสินตอน EasySlip อ่านรูปไม่ได้ (400):
     *                                                 true/null = ถือเป็นสลิป (fail-safe), false = ไม่ใช่สลิป
     */
    public function verify(
        Bot $bot,
        ?Conversation $conversation,
        ?Message $message,
        string $imageUrl,
        array $conversationHistory,
        ?\Closure $isSlipCheck = null,
    ): SlipVerificationResult {
        $token = $bot->user?->settings?->getEasySlipApiToken();
        if (! $token) {
            Log::warning('Slip verification enabled but EasySlip token missing', ['bot_id' => $bot->id]);

            return $this->record($bot, $conversation, $message, null, new SlipVerificationResult(
                isSlip: false, passed: false, failReason: 'config_error',
            ));
        }

        try {
            $response = Http::timeout(15)
                ->withToken($token)
                ->post(self::VERIFY_URL, ['url' => $imageUrl, 'checkDuplicate' => false]);
        } catch (ConnectionException $e) {
            Log::warning('EasySlip connection failed', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);

            return $this->record($bot, $conversation, $message, null, new SlipVerificationResult(
                isSlip: false, passed: false, failReason: 'api_error',
            ));
        }

        if ($response->status() === 400) {
            // EasySlip อ่านรูปไม่ได้ (ไม่ใช่สลิป หรือสลิปเบลอ/มืด/ครอปเกิน)
            // มีออเดอร์ค้าง → ถาม vision ก่อน ($isSlipCheck) ว่ารูปเป็นสลิปจริงไหม
            //   false = รูปทั่วไป (เช่น screenshot อื่น) → ไป vision ตอบตามบริบท ไม่ alert
            //   true/null (ไม่แน่ใจ/เรียกไม่ได้) = ถือเป็นสลิปอ่านไม่ได้ → alert แอดมิน (fail-safe)
            // ไม่มีออเดอร์ค้าง → เป็นรูปทั่วไป → พฤติกรรมเดิม (ไป vision, ไม่บันทึก)
            $configured = (string) ($bot->settings?->slip_receiver_account ?? '');
            if ($this->findExpectedPayment($conversationHistory, $configured) !== null
                && ($isSlipCheck === null || $isSlipCheck() !== false)) {
                return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                    isSlip: true, passed: false, failReason: 'unreadable',
                ));
            }

            return new SlipVerificationResult(isSlip: false, passed: false);
        }

        if ($response->status() === 404) {
            // อ่าน QR ได้แต่ไม่พบธุรกรรมในระบบธนาคาร → สลิปปลอม/สลิปเก่าผิดปกติ
            // ยกเว้น SLIP_PENDING (ธนาคารกรุงเทพยังประมวลผลไม่เสร็จ <5 นาที) → ไม่ใช่ของปลอม
            $failReason = $response->json('error.code') === 'SLIP_PENDING' ? 'pending' : 'fake';

            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: $failReason,
            ));
        }

        if (! $response->successful()) {
            Log::warning('EasySlip API error', [
                'bot_id' => $bot->id, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500),
            ]);

            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: false, passed: false, failReason: 'api_error',
            ));
        }

        $data = $response->json('data');
        if (! is_array($data) || empty($data['rawSlip']['transRef'])) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: false, passed: false, failReason: 'api_error',
            ));
        }

        $transRef = (string) $data['rawSlip']['transRef'];
        $slipAmount = (float) ($data['amountInSlip'] ?? $data['rawSlip']['amount']['amount'] ?? 0);
        $receiverAccount = (string) ($data['rawSlip']['receiver']['account']['bank']['account'] ?? '');

        // เช็ค 1: บัญชีปลายทางต้องเป็นบัญชีร้าน
        $configured = (string) ($bot->settings?->slip_receiver_account ?? '');
        if ($configured === '' || ! self::accountMatches($configured, $receiverAccount)) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'wrong_account',
                amount: $slipAmount, transRef: $transRef,
            ), $receiverAccount);
        }

        // เช็ค 2: สลิปซ้ำ (เคย passed แล้วใน bot นี้)
        $isDuplicate = SlipVerification::where('bot_id', $bot->id)
            ->where('trans_ref', $transRef)
            ->where('status', 'passed')
            ->exists();
        if ($isDuplicate) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'duplicate',
                amount: $slipAmount, transRef: $transRef,
            ), $receiverAccount);
        }

        // เช็ค 3: ต้องมีออเดอร์ค้างชำระใน history
        $expected = $this->findExpectedPayment($conversationHistory, $configured);
        if ($expected === null) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'no_pending_order',
                amount: $slipAmount, transRef: $transRef,
            ), $receiverAccount);
        }

        // เช็ค 4: ยอดต้องตรง (± tolerance)
        $tolerance = (float) ($bot->settings?->slip_amount_tolerance ?? 0);
        if (abs($slipAmount - $expected['total']) > $tolerance) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'amount_mismatch',
                amount: $slipAmount, transRef: $transRef,
                expectedAmount: $expected['total'], orderSummary: $expected['summary'],
            ), $receiverAccount);
        }

        return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
            isSlip: true, passed: true,
            amount: $slipAmount, transRef: $transRef,
            expectedAmount: $expected['total'], orderSummary: $expected['summary'],
            orderItems: $expected['items'],
        ), $receiverAccount);
    }

    /**
     * แจ้งแอดมินผ่าน Telegram เมื่อตรวจสลิปไม่ผ่าน (ไม่ throw — ไม่มี plugin ก็แค่ log warning)
     */
    public function notifyAdmin(Bot $bot, ?Conversation $conversation, SlipVerificationResult $result): void
    {
        $flow = $conversation?->currentFlow ?? $bot->defaultFlow;
        $plugin = $flow?->plugins()
            ->where('type', 'telegram')
            ->where('enabled', true)
            ->first();

        if (! $plugin) {
            Log::warning('Slip alert: no enabled telegram plugin', ['bot_id' => $bot->id]);

            return;
        }

        $token = $plugin->config['access_token'] ?? '';
        $chatId = $plugin->config['chat_id'] ?? '';
        if (empty($token) || empty($chatId)) {
            Log::warning('Slip alert: telegram plugin missing config', ['plugin_id' => $plugin->id]);

            return;
        }

        $reason = self::FAIL_REASON_LABELS[$result->failReason] ?? ($result->failReason ?? 'unknown');
        $header = in_array($result->failReason, self::FRAUD_REASONS, true)
            ? "🚨 สลิปมีปัญหา — อย่าเพิ่งส่งของ ({$bot->name})"
            : "⚠️ ระบบตรวจสลิปไม่ได้ — รบกวนตรวจมือ ({$bot->name})";
        $lines = [$header];
        if ($conversation !== null) {
            $displayName = $conversation->customerProfile?->display_name;
            $lines[] = $displayName !== null
                ? "ลูกค้า: {$displayName} (แชท #{$conversation->id})"
                : "แชท #{$conversation->id}";
        }
        $lines[] = "เหตุผล: {$reason}";
        if ($result->amount !== null) {
            $lines[] = 'ยอดในสลิป: '.self::formatBaht($result->amount).' บาท';
        }
        if ($result->expectedAmount !== null) {
            $lines[] = 'ยอดออเดอร์: '.self::formatBaht($result->expectedAmount).' บาท';
        }
        $lines[] = 'กรุณาเช็คในแชทก่อนยืนยัน';

        $keyboard = $this->buildConfirmKeyboard($conversation, $result);
        $this->alertBot->sendMessage($token, $chatId, implode("\n", $lines), $keyboard);
    }

    /**
     * แสดงยอดเงินแบบไม่มีทศนิยมถ้าเป็นจำนวนเต็ม
     */
    private static function formatBaht(float $value): string
    {
        return number_format($value, fmod($value, 1) == 0.0 ? 0 : 2);
    }

    /**
     * สร้าง inline_keyboard ปุ่มยืนยันตามยอดที่รู้และประเภทเคส (fraud → prefix pa).
     * คืน null เมื่อไม่มี conversation (resolve ตอน callback ไม่ได้).
     *
     * @return array<int, array<int, array{text: string, callback_data: string}>>|null
     */
    private function buildConfirmKeyboard(?Conversation $conversation, SlipVerificationResult $result): ?array
    {
        if ($conversation === null) {
            return null;
        }

        $action = in_array($result->failReason, self::FRAUD_REASONS, true) ? 'pa' : 'pc';
        $id = $conversation->id;
        $orderAmt = $result->expectedAmount;
        $slipAmt = $result->amount;

        $btn = fn (string $text, string $amt) => [['text' => $text, 'callback_data' => "{$action}|{$id}|{$amt}"]];

        if ($orderAmt !== null && $slipAmt !== null && $orderAmt != $slipAmt) {
            return [
                $btn('✅ ยอดออเดอร์ '.self::formatBaht($orderAmt), (string) $orderAmt),
                $btn('✅ ยอดในสลิป '.self::formatBaht($slipAmt), (string) $slipAmt),
            ];
        }
        if ($orderAmt !== null) {
            return [$btn('✅ ยืนยันรับเงิน '.self::formatBaht($orderAmt).' บาท', (string) $orderAmt)];
        }
        if ($slipAmt !== null) {
            return [$btn('✅ ยืนยันรับเงิน '.self::formatBaht($slipAmt).' บาท', (string) $slipAmt)];
        }

        return [$btn('✅ ยืนยัน (ใช้ยอดจากแชท)', 'x')];
    }

    /**
     * บันทึกผลการตรวจลงตาราง slip_verifications (ไม่ throw — ประวัติพังต้องไม่ล้มการตอบ)
     */
    private function record(
        Bot $bot,
        ?Conversation $conversation,
        ?Message $message,
        ?array $rawResponse,
        SlipVerificationResult $result,
        ?string $receiverAccount = null,
    ): SlipVerificationResult {
        try {
            $created = SlipVerification::create([
                'bot_id' => $bot->id,
                'conversation_id' => $conversation?->id,
                'message_id' => $message?->id,
                'trans_ref' => $result->transRef,
                'amount' => $result->amount,
                'receiver_account' => $receiverAccount,
                'status' => $result->status(),
                'raw_response' => $rawResponse,
            ]);
            $result->slipVerificationId = $created->id;
        } catch (\Throwable $e) {
            Log::error('Failed to record slip verification', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);
        }

        return $result;
    }
}
