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
        'needs_choice' => 'ลูกค้าโอนข้ามขั้นตอน — ระบบสรุปได้หลายแบบ กรุณาเลือกรายการที่ถูกต้อง',
        'unreadable' => 'รูปสลิปอ่านไม่ได้/ไม่ชัด — ระบบตรวจอัตโนมัติไม่ได้ กรุณาตรวจมือ',
        'api_error' => 'ระบบตรวจสลิป (EasySlip) ใช้งานไม่ได้ชั่วคราว',
        'config_error' => 'ตั้งค่าไม่ครบ — EasySlip token หายไป กรุณาใส่ที่หน้า Settings (ระบบจะไม่ตรวจสลิปจนกว่าจะแก้)',
        'image_download_failed' => 'โหลดรูปจากลูกค้าไม่สำเร็จ — ระบบตรวจสลิปไม่ได้ กรุณาเปิดแชทดูรูป/ยอดเอง',
    ];

    // ห้ามประกาศ dependency พวกนี้เป็น optional (`?Type $x = null`) — Laravel เจอ parameter
    // ที่มีค่า default จะใช้ default ทันทีโดยไม่ resolve ให้ ผลคือ $itemExtractor เป็น null
    // บน production มาตั้งแต่ 11 ก.ค. (LLM fallback ไม่เคยทำงานเลย) เทสต์มองไม่เห็นเพราะ
    // สร้าง service เองด้วย new พร้อมส่ง dependency เข้าไป
    public function __construct(
        private readonly PaymentMessageDetector $detector,
        private readonly TelegramAlertBotService $alertBot,
        private readonly LLMOrderItemExtractor $itemExtractor,
        private readonly OrderReconstructor $reconstructor,
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
    public function findExpectedPayment(array $conversationHistory, ?string $receiverAccount = null, ?Bot $bot = null): ?array
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

            return $this->buildExpected($data, $content, $bot);
        }

        return null;
    }

    /**
     * แปลงผล parse (จาก parsePaymentData/parseConfirmData) เป็น expected shape:
     * normalize ชื่อ → LLM fallback ชั้น 2 (ใต้ flag) → ตัดของแถมราคา 0 ออกจาก summary.
     * $requireItems: confirm-path ต้องได้ items จริงเท่านั้น (คืน null ถ้าว่าง) —
     * ต่างจาก payment-summary ที่ยอมคืน summary '-' เพื่อให้ยอดยังใช้ยืนยันเงินได้
     *
     * @return array{total: float, summary: string, items: array}|null
     */
    private function buildExpected(array $data, string $content, ?Bot $bot, bool $requireItems = false): ?array
    {
        $items = array_map(function (array $item) {
            $item['name'] = rtrim(trim($item['name']), '= ');

            return $item;
        }, $data['items']);

        // ชั้น 2 fallback: regex ได้ total แต่ดึง items ไม่ได้ (prose ล้วน / หลายสินค้าบรรทัดเดียว)
        // เรียกเฉพาะตอน items ว่างเท่านั้น (cost guard) — ไม่เรียกทุกครั้ง
        if ($items === [] && $bot !== null
            && config('delivery.llm_item_fallback_enabled', true)) {
            $items = $this->itemExtractor->extract($content, $bot);
        }

        if ($items === [] && $requireItems) {
            return null;
        }

        // ตัดของแถมราคา 0 ออกจาก summary กันชื่อหลุดไปข้อความยืนยัน/Telegram/order_items —
        // แต่คืน 'items' เต็มชุดให้ delivery กรอง+log เองอีกชั้น
        $visibleItems = array_filter($items, fn (array $item) => ! PaymentMessageDetector::isZeroPriceItem($item));
        // ติด "xN" ท้ายชื่อเมื่อสั่งเกิน 1 — summary เป็นต้นทางเดียวของจำนวนที่ไหลไปข้อความยืนยัน,
        // การ์ด Telegram และ order_items (parseProductItems อ่านรูป "ชื่อ xN"); ทิ้ง qty ที่นี่
        // = ออเดอร์ 2 ชุดถูกบันทึกเป็น 1 เงียบๆ
        $itemNames = array_map(
            fn (array $item) => (int) ($item['qty'] ?? 1) > 1 ? "{$item['name']} x{$item['qty']}" : $item['name'],
            $visibleItems,
        );

        return [
            'total' => (float) str_replace(',', '', $data['total']),
            'summary' => $itemNames === [] ? '-' : implode(', ', $itemNames),
            'items' => $items,
        ];
    }

    /**
     * Fallback ชั้น 3 (เฉพาะ manual confirm): อ่านออเดอร์จากข้อความยืนยันขั้น 2 ของบอท
     * ("...รวม X บาท ถูกต้องไหมครับ? พิมพ์ยืนยัน") เมื่อไม่มีข้อความสรุปยอด+เลขบัญชีใน history
     * — เคสลูกค้าโอนข้ามขั้นตอน. ห้ามใช้ตัดสิน EasySlip auto-pass: หลักฐานอ่อนกว่าสรุปยอด
     * จึงต้องมีคนกดยืนยันก่อนเสมอ แล้วยอดที่กด ($confirmedAmount) เป็นตัว guard
     * (ต้องตรงยอดในข้อความ ± slip_amount_tolerance) กันคว้าข้อความเก่าคนละออเดอร์.
     *
     * @param  array<int, array{sender: string, content: string}>  $conversationHistory
     * @return array{total: float, summary: string, items: array}|null
     */
    public function findExpectedFromConfirmMessage(array $conversationHistory, ?Bot $bot, float $confirmedAmount): ?array
    {
        $tolerance = (float) ($bot?->settings?->slip_amount_tolerance ?? 0);

        foreach (array_reverse($conversationHistory) as $msg) {
            if (($msg['sender'] ?? '') !== 'bot') {
                continue;
            }
            $content = $msg['content'] ?? '';
            if (! $this->detector->isConfirmMessage($content)) {
                continue;
            }
            $data = $this->detector->parseConfirmData($content);
            if ($data === null) {
                continue;
            }

            $total = (float) str_replace(',', '', $data['total']);
            if (abs($total - $confirmedAmount) > $tolerance) {
                Log::info('Confirm fallback: amount mismatch, skipping message', [
                    'confirmed' => $confirmedAmount, 'found' => $total,
                ]);

                continue;
            }

            $expected = $this->buildExpected($data, $content, $bot, requireItems: true);
            if ($expected === null) {
                // ยอดตรงแต่ดึงรายการไม่ได้ (prose ล้วน) — ไล่ดูข้อความยืนยันก่อนหน้าต่อ
                // ห้ามหยุดทั้งลูป ไม่งั้นข้อความตะกร้าที่มีรายการครบจะไม่ถูกอ่าน (เคสจริงแชท #1072)
                continue;
            }

            Log::info('Confirm fallback: items extracted from step-2 confirm message', [
                'count' => count($expected['items']),
            ]);

            return $expected;
        }

        return null;
    }

    /**
     * @param  (\Closure(): ?bool)|null  $isSlipCheck  ตัวช่วยตัดสินทุกครั้งที่ EasySlip บอกอะไรเราไม่ได้
     *                                                 (อ่านรูปไม่ออก 400 / token หาย / API ล่ม / ตอบเพี้ยน):
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

            return $this->apiUnavailable($bot, $conversation, $message, null, 'config_error', $isSlipCheck);
        }

        try {
            $response = Http::timeout(15)
                ->withToken($token)
                ->post(self::VERIFY_URL, ['url' => $imageUrl, 'checkDuplicate' => false]);
        } catch (ConnectionException $e) {
            Log::warning('EasySlip connection failed', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);

            return $this->apiUnavailable($bot, $conversation, $message, null, 'api_error', $isSlipCheck);
        }

        if ($response->status() === 400) {
            // EasySlip อ่านรูปไม่ได้ (ไม่ใช่สลิป หรือสลิปเบลอ/มืด/ครอปเกิน)
            // มีออเดอร์ค้าง → ถาม vision ก่อน ($isSlipCheck) ว่ารูปเป็นสลิปจริงไหม
            //   false = รูปทั่วไป (เช่น screenshot อื่น) → ไป vision ตอบตามบริบท ไม่ alert
            //   true/null (ไม่แน่ใจ/เรียกไม่ได้) = ถือเป็นสลิปอ่านไม่ได้ → alert แอดมิน (fail-safe)
            // ไม่มีออเดอร์ค้าง → เป็นรูปทั่วไป → พฤติกรรมเดิม (ไป vision, ไม่บันทึก)
            $configured = (string) ($bot->settings?->slip_receiver_account ?? '');
            if ($this->findExpectedPayment($conversationHistory, $configured) !== null
                && ! $this->visionSaysNotSlip($isSlipCheck)) {
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

            return $this->apiUnavailable($bot, $conversation, $message, $response->json(), 'api_error', $isSlipCheck);
        }

        $data = $response->json('data');
        if (! is_array($data) || empty($data['rawSlip']['transRef'])) {
            return $this->apiUnavailable($bot, $conversation, $message, $response->json(), 'api_error', $isSlipCheck);
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
        // ด่าน 1 ข้อความสรุปยอด+เลขบัญชี → ด่าน 2 ข้อความยืนยันขั้น 2 (ยอดต้องตรง)
        // → ด่าน 3 ให้ระบบสรุปออเดอร์เองจากบทสนทนา (เรียก LLM เฉพาะตอนสองด่านแรกพลาด)
        $orderSource = 'summary';
        $expected = $this->findExpectedPayment($conversationHistory, $configured, $bot);
        if ($expected === null) {
            $orderSource = 'confirm';
            $expected = $this->findExpectedFromConfirmMessage($conversationHistory, $bot, $slipAmount);
        }

        $reconstruction = null;
        if ($expected === null) {
            $reconstruction = $this->reconstructor->reconstruct($bot, $conversationHistory, $slipAmount);
            if ($reconstruction !== null) {
                $orderSource = 'llm';
                $expected = [
                    'total' => $reconstruction->total,
                    'summary' => $reconstruction->summary,
                    'items' => $reconstruction->items,
                ];
            }
        }

        if ($expected === null) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'no_pending_order',
                amount: $slipAmount, transRef: $transRef,
            ), $receiverAccount);
        }

        // ระบบสรุปได้แต่ประกอบยอดได้หลายแบบ (เช่น 1,100 ตรงทั้ง Personal และ BM)
        // → ห้ามส่งของเอง ต้องให้เจ้าของกดเลือกจากการ์ด Telegram
        if ($reconstruction?->ambiguous) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'needs_choice',
                amount: $slipAmount, transRef: $transRef,
                expectedAmount: $reconstruction->total, orderSummary: $reconstruction->summary,
                orderSource: 'llm', reconstruction: $reconstruction,
            ), $receiverAccount);
        }

        // เช็ค 4: ยอดต้องตรง (± tolerance)
        $tolerance = (float) ($bot->settings?->slip_amount_tolerance ?? 0);
        if (abs($slipAmount - $expected['total']) > $tolerance) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'amount_mismatch',
                amount: $slipAmount, transRef: $transRef,
                expectedAmount: $expected['total'], orderSummary: $expected['summary'],
                orderSource: $orderSource, reconstruction: $reconstruction,
            ), $receiverAccount);
        }

        return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
            isSlip: true, passed: true,
            amount: $slipAmount, transRef: $transRef,
            expectedAmount: $expected['total'], orderSummary: $expected['summary'],
            orderItems: $expected['items'],
            orderSource: $orderSource, reconstruction: $reconstruction,
        ), $receiverAccount);
    }

    /**
     * นโยบาย fail-safe กลางของทุก branch ที่ EasySlip บอกอะไรเราไม่ได้ — เขียนที่เดียว
     * เพราะทั้ง branch 400 และ apiUnavailable ต้องตัดสินเรื่องเดียวกันเสมอ
     * true = สายตายืนยันว่าไม่ใช่สลิป (เงียบได้) เท่านั้น; ไม่แน่ใจ/ถามไม่ได้ = เข้าข้างเงิน
     *
     * @param  (\Closure(): ?bool)|null  $isSlipCheck
     */
    private function visionSaysNotSlip(?\Closure $isSlipCheck): bool
    {
        return $isSlipCheck !== null && $isSlipCheck() === false;
    }

    /**
     * EasySlip ให้คำตอบเรื่องรูปนี้ไม่ได้เลย (token หาย / API ล่ม / ตอบเพี้ยน)
     * → ระบบไม่รู้ด้วยซ้ำว่ารูปเป็นสลิปไหม จึงถามสายตา ($isSlipCheck) ก่อนกวนเจ้าของ
     *   false ชัดเจน = รูปทั่วไป (เช่น screenshot หน้าเพจ) → เงียบ ไม่ record ไม่ alert ปล่อยไป vision
     *   true / ไม่แน่ใจ / ถามไม่ได้ = ถือเป็นสลิป → record + ให้ปลายทาง alert (fail-safe เข้าข้างเงิน)
     */
    private function apiUnavailable(
        Bot $bot,
        ?Conversation $conversation,
        ?Message $message,
        ?array $rawResponse,
        string $failReason,
        ?\Closure $isSlipCheck,
    ): SlipVerificationResult {
        if ($this->visionSaysNotSlip($isSlipCheck)) {
            return new SlipVerificationResult(isSlip: false, passed: false);
        }

        return $this->record($bot, $conversation, $message, $rawResponse, new SlipVerificationResult(
            isSlip: false, passed: false, failReason: $failReason,
        ));
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
        $botName = TelegramAlertBotService::esc($bot->name);

        $header = match (true) {
            $result->passed => "🤖 <b>ลูกค้าโอนข้ามขั้นตอน — ระบบสรุปออเดอร์เองแล้ว</b> ({$botName})",
            $result->failReason === 'needs_choice' => "🤔 <b>ลูกค้าโอนข้ามขั้นตอน — เลือกรายการที่ถูกต้อง</b> ({$botName})",
            in_array($result->failReason, self::FRAUD_REASONS, true) => "🚨 <b>สลิปมีปัญหา — อย่าเพิ่งส่งของ</b> ({$botName})",
            default => "⚠️ <b>ระบบตรวจสลิปไม่ได้ — รบกวนตรวจมือ</b> ({$botName})",
        };

        $lines = [$header];
        if ($conversation !== null) {
            $displayName = $conversation->customerProfile?->display_name;
            $lines[] = $displayName !== null
                ? '👤 '.TelegramAlertBotService::esc($displayName)." · แชท #{$conversation->id}"
                : "👤 แชท #{$conversation->id}";
        }
        if (! $result->passed) {
            $lines[] = 'เหตุผล: <b>'.TelegramAlertBotService::esc($reason).'</b>';
        }
        if ($result->amount !== null) {
            $lines[] = 'ยอดในสลิป: <code>'.self::formatBaht($result->amount).'</code> บาท';
        }
        if ($result->expectedAmount !== null && ! $result->passed) {
            $lines[] = 'ยอดออเดอร์: <code>'.self::formatBaht($result->expectedAmount).'</code> บาท';
        }
        if ($result->orderSummary !== null && $result->orderSummary !== '-') {
            $lines[] = 'ออเดอร์: <b>'.TelegramAlertBotService::esc($result->orderSummary).'</b>';
        }
        $lines[] = $result->passed
            ? 'ส่งของให้แล้ว ไม่ต้องทำอะไรครับ — ถ้าไม่ถูกต้องรบกวนเปิดแชทแก้'
            : 'กรุณาเช็คในแชทก่อนยืนยัน';

        $keyboard = $result->passed ? null : $this->buildConfirmKeyboard($conversation, $result);
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

        // กำกวม: หนึ่งปุ่มต่อหนึ่งตัวเลือกที่ประกอบยอดได้ — เจ้าของกดปุ่มเดียวจบ
        if ($result->failReason === 'needs_choice' && $result->slipVerificationId !== null) {
            $slip = SlipVerification::find($result->slipVerificationId);
            $alternatives = $slip?->reconstructed['alternatives'] ?? [];
            $rows = [];
            foreach ($alternatives as $index => $set) {
                $label = implode(', ', array_map(
                    fn (array $item) => ((int) ($item['qty'] ?? 1)) > 1
                        ? "{$item['name']} x{$item['qty']}"
                        : $item['name'],
                    $set,
                ));
                $rows[] = [['text' => "✅ {$label}", 'callback_data' => "po|{$result->slipVerificationId}|{$index}"]];
            }
            if ($rows !== []) {
                return $rows;
            }
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
                'order_source' => $result->orderSource,
                'reconstructed' => $result->reconstruction === null ? null : [
                    'items' => $result->reconstruction->items,
                    'alternatives' => $result->reconstruction->alternatives,
                ],
            ]);
            $result->slipVerificationId = $created->id;
        } catch (\Throwable $e) {
            Log::error('Failed to record slip verification', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);
        }

        return $result;
    }
}
