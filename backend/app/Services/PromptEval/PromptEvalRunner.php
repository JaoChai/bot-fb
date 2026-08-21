<?php

namespace App\Services\PromptEval;

use App\Models\Bot;
use App\Services\AIService;
use App\Services\Guardrail\OffTopicSignalExtractor;
use App\Services\RAGService;
use Throwable;

/**
 * รัน regression test case เดียวกับ system prompt จริงผ่าน AIService/RAGService แล้วเทียบ
 * เนื้อหาที่ตอบกลับกับเงื่อนไขที่ case กำหนด (ดู task-1-brief.md สำหรับ case schema เต็ม)
 * ไม่เขียนอะไรลง DB — เรียกด้วย conversation: null เสมอ
 */
class PromptEvalRunner
{
    public function __construct(
        private readonly AIService $ai,
        private readonly RAGService $rag,
    ) {}

    /**
     * @param  array<string, mixed>  $case
     */
    public function run(Bot $bot, array $case): CaseResult
    {
        $start = microtime(true);
        $response = '';
        $cost = 0.0;
        $failures = [];

        try {
            $rawResult = $this->callLlm($bot, $case);
        } catch (Throwable $e) {
            $failures[] = "เรียก LLM ล้มเหลว: {$e->getMessage()}";
            $rawResult = null;
        }

        if ($rawResult !== null) {
            $rawContent = (string) ($rawResult['content'] ?? '');
            $cost = (float) ($rawResult['cost'] ?? 0.0);

            // RAGService (ต่างจาก AIService::generateResponse) ไม่ตัด marker [[OFFTOPIC]]/
            // [[ORDER]]...[[/ORDER]] ออกให้เอง — เทียบด้วยคีย์ที่มี/ไม่มีใน $rawResult ว่าเดินทางไหน
            // แล้วตัดเองก่อนเอาไปเทียบ must_contain/must_not_contain ให้ตรงกับ "ข้อความที่ลูกค้า
            // เห็นจริง" เหมือน AIService ทำกับทุกคำตอบ
            [$response, $offTopicTriggered] = $this->resolveDisplayResponse($rawResult, $rawContent);

            if (! array_key_exists('order_payload', $rawResult)) {
                $response = $this->stripOrderBlock($response);
            }

            $failures = $this->evaluate($case, $rawResult, $rawContent, $response, $offTopicTriggered);
        }

        return new CaseResult(
            id: $case['id'],
            label: $case['label'],
            passed: $failures === [],
            response: $response,
            failures: $failures,
            durationMs: (int) round((microtime(true) - $start) * 1000),
            cost: $cost,
        );
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function callLlm(Bot $bot, array $case): array
    {
        $history = $case['history'] ?? [];

        if ($history === []) {
            return $this->ai->generateResponse($bot, $case['message'], null);
        }

        return $this->rag->generateResponse(
            bot: $bot,
            userMessage: $case['message'],
            conversationHistory: $history,
            conversation: null,
            flow: $bot->defaultFlow,
        );
    }

    /**
     * @param  array<string, mixed>  $rawResult
     * @return array{0: string, 1: bool}
     */
    private function resolveDisplayResponse(array $rawResult, string $rawContent): array
    {
        if (array_key_exists('off_topic_triggered', $rawResult)) {
            return [$rawContent, ! empty($rawResult['off_topic_triggered'])];
        }

        $extracted = (new OffTopicSignalExtractor)->extract($rawContent);

        return [$extracted['clean'], $extracted['triggered']];
    }

    private function stripOrderBlock(string $content): string
    {
        return trim((string) preg_replace('/\[\[ORDER\]\].*?\[\[\/ORDER\]\]/su', '', $content));
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $result
     * @return array<int, string>
     */
    private function evaluate(array $case, array $result, string $rawContent, string $response, bool $offTopicTriggered): array
    {
        $failures = [];
        $normalized = $this->normalize($response);

        foreach ($case['must_contain'] ?? [] as $group) {
            $found = false;
            foreach ($group as $needle) {
                if ($this->containsNeedle($normalized, $needle)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $failures[] = 'ต้องมี "'.implode('" หรือ "', $group).'" แต่ไม่พบ';
            }
        }

        foreach ($case['must_not_contain'] ?? [] as $needle) {
            if ($this->containsNeedle($normalized, $needle)) {
                $failures[] = "ต้องไม่มี \"{$needle}\" แต่พบ";
            }
        }

        if (array_key_exists('expect_off_topic', $case)) {
            if ($case['expect_off_topic'] !== $offTopicTriggered) {
                $failures[] = $case['expect_off_topic']
                    ? 'ต้อง trigger off-topic แต่ไม่ trigger'
                    : 'ไม่ควร trigger off-topic แต่ trigger';
            }
        }

        if (isset($case['expect_order'])) {
            $failures = array_merge($failures, $this->evaluateExpectOrder($case['expect_order'], $result, $rawContent));
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $expectOrder
     * @param  array<string, mixed>  $result
     * @return array<int, string>
     */
    private function evaluateExpectOrder(array $expectOrder, array $result, string $rawContent): array
    {
        $payload = array_key_exists('order_payload', $result)
            ? $result['order_payload']
            : $this->extractOrderPayload($rawContent);

        if ($payload === null) {
            return ['ต้องมี order_payload แต่ไม่มี'];
        }

        $failures = [];
        foreach ($expectOrder as $key => $expected) {
            $actual = $payload[$key] ?? null;
            if ($actual != $expected) {
                $failures[] = "expect_order[{$key}] ต้องเป็น ".var_export($expected, true).' แต่ได้ '.var_export($actual, true);
            }
        }

        return $failures;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractOrderPayload(string $content): ?array
    {
        if (preg_match('/\[\[ORDER\]\](.*?)\[\[\/ORDER\]\]/s', $content, $matches) !== 1) {
            return null;
        }

        $decoded = json_decode($matches[1], true);

        return is_array($decoded) ? $decoded : null;
    }

    private function containsNeedle(string $haystack, string $needle): bool
    {
        if (mb_strlen($needle) >= 2 && str_starts_with($needle, '/') && str_ends_with($needle, '/')) {
            return preg_match($needle, $haystack) === 1;
        }

        return mb_stripos($haystack, $needle) !== false;
    }

    private function normalize(string $text): string
    {
        $text = str_replace('|||', '', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
