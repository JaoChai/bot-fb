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
    private const VERIFY_URL = 'https://developer.easyslip.com/api/v1/verify';

    private const FAIL_REASON_LABELS = [
        'fake' => 'ไม่พบธุรกรรมในระบบธนาคาร (อาจเป็นสลิปปลอม)',
        'duplicate' => 'สลิปซ้ำ (เคยใช้ยืนยันไปแล้ว)',
        'amount_mismatch' => 'ยอดไม่ตรงกับออเดอร์',
        'wrong_account' => 'โอนเข้าบัญชีอื่น (ไม่ใช่บัญชีร้าน)',
        'no_pending_order' => 'ไม่พบออเดอร์ค้างชำระในบทสนทนา',
        'api_error' => 'ระบบตรวจสลิป (EasySlip) ใช้งานไม่ได้ชั่วคราว',
    ];

    public function __construct(
        private readonly PaymentMessageDetector $detector,
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
     * @return array{total: float, summary: string}|null
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

            $itemNames = array_map(fn (array $item) => rtrim(trim($item['name']), '= '), $data['items']);

            return [
                'total' => (float) str_replace(',', '', $data['total']),
                'summary' => $itemNames === [] ? '-' : implode(', ', $itemNames),
            ];
        }

        return null;
    }

    public function verify(
        Bot $bot,
        ?Conversation $conversation,
        ?Message $message,
        string $imageUrl,
        array $conversationHistory,
    ): SlipVerificationResult {
        $token = $bot->user?->settings?->getEasySlipApiToken();
        if (! $token) {
            Log::warning('Slip verification enabled but EasySlip token missing', ['bot_id' => $bot->id]);

            return $this->record($bot, $conversation, $message, null, new SlipVerificationResult(
                isSlip: false, passed: false, failReason: 'api_error',
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
            // รูปไม่ใช่สลิป/อ่านไม่ได้ → ไป vision flow เดิม ไม่บันทึก
            return new SlipVerificationResult(isSlip: false, passed: false);
        }

        if ($response->status() === 404) {
            // อ่าน QR ได้แต่ไม่พบธุรกรรมในระบบธนาคาร → สลิปปลอม/สลิปเก่าผิดปกติ
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: true, passed: false, failReason: 'fake',
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
        if (! is_array($data) || empty($data['transRef'])) {
            return $this->record($bot, $conversation, $message, $response->json(), new SlipVerificationResult(
                isSlip: false, passed: false, failReason: 'api_error',
            ));
        }

        $transRef = (string) $data['transRef'];
        $slipAmount = (float) ($data['amount']['amount'] ?? 0);
        $receiverAccount = (string) ($data['receiver']['account']['bank']['account'] ?? '');

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
        ), $receiverAccount);
    }

    /**
     * แจ้งแอดมินผ่าน Telegram เมื่อตรวจสลิปไม่ผ่าน (ไม่ throw — ไม่มี plugin ก็แค่ log warning)
     */
    public function notifyAdmin(Bot $bot, ?Conversation $conversation, SlipVerificationResult $result): void
    {
        $plugin = $bot->defaultFlow?->plugins()
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
        $lines = ["⚠️ ตรวจสลิปไม่ผ่าน — {$bot->name}", "เหตุผล: {$reason}"];
        if ($result->amount !== null) {
            $lines[] = 'ยอดในสลิป: '.number_format($result->amount, 2).' บาท';
        }
        if ($result->expectedAmount !== null) {
            $lines[] = 'ยอดออเดอร์: '.number_format($result->expectedAmount, 2).' บาท';
        }
        if ($result->transRef !== null) {
            $lines[] = "เลขอ้างอิง: {$result->transRef}";
        }
        if ($conversation !== null) {
            $lines[] = "Conversation: #{$conversation->id}";
        }
        $lines[] = 'กรุณาตรวจสอบในแชทด่วน';

        try {
            Http::timeout(5)->retry(2, 500)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => implode("\n", $lines),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Slip alert: telegram send failed', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);
        }
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
            SlipVerification::create([
                'bot_id' => $bot->id,
                'conversation_id' => $conversation?->id,
                'message_id' => $message?->id,
                'trans_ref' => $result->transRef,
                'amount' => $result->amount,
                'receiver_account' => $receiverAccount,
                'status' => $result->status(),
                'raw_response' => $rawResponse,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to record slip verification', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);
        }

        return $result;
    }
}
