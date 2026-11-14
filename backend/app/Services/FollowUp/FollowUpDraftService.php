<?php

namespace App\Services\FollowUp;

use App\Exceptions\OpenRouterException;
use App\Models\Bot;
use App\Services\OpenRouterService;
use App\Support\FollowUpRedactor;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * ลูกของ chat model สำหรับขั้นร่างข้อความตามลูกค้า (การ์ด B3b, plan §3.5, D27-D29)
 *
 * สัญญา:
 * - เรียก OpenRouterService::chat ครั้งเดียว: model = resolvedChatModel,
 *   fallbackModelOverride = fallback_chat_model, useFallback true, temperature 0.4,
 *   maxTokens = writer_max_tokens (D28: 1024 — reasoning tokens นับรวมในงบ),
 *   timeout = writer_timeout_seconds, reasoning null, responseFormat null
 * - messages: system = writer system prompt (D27 invite-only), user = JSON(group, conversation)
 *   — ไม่ส่ง system prompt หรือ KB ของบอท
 * - คืน null โดยไม่ยิงเมื่อ group ไม่ใช่ G2/G3 หรือบอทไม่มี chat model
 * - fail closed (D29): trim แล้วว่าง, finish_reason !== 'stop', เกิน max_text_code_points
 *   code points, เกิน 3 บรรทัดที่ไม่ว่าง, assertSafeOutbound ไม่ผ่าน, redact(text) !== text,
 *   มี '@', หรือมีจำนวนเงิน/เปอร์เซ็นต์ — ไม่มีข้อความสำรอง ไม่มีการตัดให้สั้น
 * - egress guard ฝั่งบริบทก่อนยิง (D30): list ไม่ว่าง ≤ MAX_LINES บรรทัด ทุกบรรทัด
 *   ไม่มี account data และ redact(line) === line
 * - log ล้มเหลว: warning เดียวต่อครั้ง context มีแค่ step + reason code
 *   (unsafe_context | http_error | invalid_response | rejected_draft)
 */
class FollowUpDraftService
{
    /** reason codes ตายตัวทั้งหมดที่คลาสนี้เขียนลง log */
    public const REASON_UNSAFE_CONTEXT = 'unsafe_context';

    public const REASON_HTTP_ERROR = 'http_error';

    public const REASON_INVALID_RESPONSE = 'invalid_response';

    public const REASON_REJECTED_DRAFT = 'rejected_draft';

    /** กลุ่มที่อนุญาตให้ร่าง — ที่เหลือเป็นหน้าที่ของ pipeline การ์ดอื่น */
    private const DRAFTABLE_GROUPS = ['G2', 'G3'];

    /**
     * D29 ตัวกรองเงิน/เปอร์เซ็นต์: เลขอารบิกหรือไทย (คั่น , . ได้) + เว้นวรรคได้ +
     * บาท/฿/$/ดอลลาร์/USD/THB/% (ไม่แยกตัวพิมพ์); หรือ ฿/$ ตามด้วยเลข
     */
    private const MONEY_PATTERN = '/[0-9\x{0E50}-\x{0E59}][0-9\x{0E50}-\x{0E59},.]*\s*(?:บาท|ดอลลาร์|usd|thb|%|[฿$])|[฿$][0-9\x{0E50}-\x{0E59}]/iu';

    /** บรรทัดที่ไม่ว่างสูงสุดของร่าง (D7) */
    private const MAX_NON_BLANK_LINES = 3;

    public function __construct(private OpenRouterService $openRouter) {}

    /**
     * @param  list<string>  $redactedContext  บริบทที่ผ่าน redact แล้วจาก caller
     * @return array{text: string, model: string, latency_ms: int, cost: ?float, usage: array}|null
     *                                                                                              null = ไม่ร่าง — ห้ามส่งต่อ
     */
    public function draft(Bot $bot, array $redactedContext, string $group): ?array
    {
        if (! in_array($group, self::DRAFTABLE_GROUPS, true)) {
            return null;
        }

        $model = $bot->resolvedChatModel();
        if (! is_string($model) || $model === '') {
            return null;
        }

        if (self::contextProblem($redactedContext)) {
            $this->logFailure(self::REASON_UNSAFE_CONTEXT);

            return null;
        }

        $messages = [
            ['role' => 'system', 'content' => FollowUpPrompts::writerSystem()],
            ['role' => 'user', 'content' => (string) json_encode([
                'group' => $group,
                'conversation' => array_values($redactedContext),
            ], JSON_UNESCAPED_UNICODE)],
        ];

        $startedAt = microtime(true);
        try {
            $result = $this->openRouter->chat(
                $messages,
                $model,
                0.4,
                (int) config('customer_follow_up.writer_max_tokens', 1024),
                true,
                $bot->fallback_chat_model,
                (int) config('customer_follow_up.writer_timeout_seconds', 15),
                null,
                null,
            );
        } catch (OpenRouterException) {
            $this->logFailure(self::REASON_HTTP_ERROR);

            return null;
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $content = is_array($result) ? ($result['content'] ?? null) : null;
        if (! is_string($content) || trim($content) === '') {
            $this->logFailure(self::REASON_INVALID_RESPONSE);

            return null;
        }
        $text = trim($content);

        if (($result['finish_reason'] ?? null) !== 'stop') {
            $this->logFailure(self::REASON_REJECTED_DRAFT);

            return null;
        }

        $rules = self::rejectionRule($text);
        if ($rules !== null) {
            $this->logFailure(self::REASON_REJECTED_DRAFT);

            return null;
        }

        return [
            'text' => $text,
            'model' => is_string($result['model'] ?? null) ? $result['model'] : $model,
            'latency_ms' => $latencyMs,
            'cost' => self::costFrom($result['usage'] ?? null),
            'usage' => is_array($result['usage'] ?? null) ? $result['usage'] : [],
        ];
    }

    /**
     * กฎตัดร่าง (D29 + D7 + D30) — คืน string อธิบายกฎเมื่อตัด, null เมื่อผ่านทั้งหมด
     */
    private static function rejectionRule(string $text): ?string
    {
        if (mb_strlen($text, 'UTF-8') > (int) config('customer_follow_up.max_text_code_points', 400)) {
            return 'too_long';
        }

        $nonBlank = array_filter(explode("\n", $text), fn (string $line): bool => trim($line) !== '');
        if (count($nonBlank) > self::MAX_NON_BLANK_LINES) {
            return 'too_many_lines';
        }

        try {
            FollowUpRedactor::assertSafeOutbound($text);
        } catch (InvalidArgumentException) {
            return 'unsafe_outbound';
        }

        if (FollowUpRedactor::redact($text) !== $text) {
            return 'redactable';
        }

        if (str_contains($text, '@')) {
            return 'at_sign';
        }

        $money = preg_match(self::MONEY_PATTERN, $text);
        if ($money === false || preg_last_error() !== PREG_NO_ERROR) {
            return 'unreadable';
        }
        if ($money === 1) {
            return 'money_or_percent';
        }

        return null;
    }

    /**
     * egress guard ฝั่งบริบท (D30) — true เมื่อบริบทไม่ปลอดภัย
     *
     * @param  list<string>  $redactedContext
     */
    private static function contextProblem(array $redactedContext): bool
    {
        if ($redactedContext === [] || count($redactedContext) > FollowUpRedactor::MAX_LINES) {
            return true;
        }

        foreach ($redactedContext as $line) {
            if (! is_string($line)) {
                return true;
            }
            if (FollowUpRedactor::containsAccountData($line) || FollowUpRedactor::redact($line) !== $line) {
                return true;
            }
        }

        return false;
    }

    /** cost จาก usage ของ chat — ไม่ใช่เลข = null */
    private static function costFrom(mixed $usage): ?float
    {
        $cost = is_array($usage) ? ($usage['cost'] ?? null) : null;

        return is_int($cost) || is_float($cost) ? (float) $cost : null;
    }

    /** warning เดียว มีแค่ step + reason — ไม่มีร่าง บริบท หรือข้อความ exception หลุด log */
    private function logFailure(string $reason): void
    {
        Log::warning('Follow-up draft rejected', [
            'step' => 'draft',
            'reason' => $reason,
        ]);
    }
}
