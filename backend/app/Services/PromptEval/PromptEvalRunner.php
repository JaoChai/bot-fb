<?php

namespace App\Services\PromptEval;

use App\Models\Bot;
use App\Services\AIService;
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
            $history = $case['history'] ?? [];

            if ($history === []) {
                $result = $this->ai->generateResponse($bot, $case['message'], null);
            } else {
                $result = $this->rag->generateResponse(
                    bot: $bot,
                    userMessage: $case['message'],
                    conversationHistory: $history,
                    conversation: null,
                    flow: $bot->defaultFlow,
                );
            }

            $response = (string) ($result['content'] ?? '');
            $cost = (float) ($result['cost'] ?? 0.0);

            $failures = $this->evaluate($case, $result, $response);
        } catch (Throwable $e) {
            $failures[] = "เรียก LLM ล้มเหลว: {$e->getMessage()}";
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
     * @param  array<string, mixed>  $result
     * @return array<int, string>
     */
    private function evaluate(array $case, array $result, string $response): array
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
            $triggered = ! empty($result['off_topic_triggered']);

            if ($case['expect_off_topic'] !== $triggered) {
                $failures[] = $case['expect_off_topic']
                    ? 'ต้อง trigger off-topic แต่ไม่ trigger'
                    : 'ไม่ควร trigger off-topic แต่ trigger';
            }
        }

        if (isset($case['expect_order'])) {
            $failures = array_merge($failures, $this->evaluateExpectOrder($case['expect_order'], $result, $response));
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $expectOrder
     * @param  array<string, mixed>  $result
     * @return array<int, string>
     */
    private function evaluateExpectOrder(array $expectOrder, array $result, string $response): array
    {
        $payload = array_key_exists('order_payload', $result)
            ? $result['order_payload']
            : $this->extractOrderPayload($response);

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
