<?php

namespace App\Services\FollowUp;

use App\Services\OpenRouterCredentials;
use App\Support\FollowUpRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ลูกของ Luna Decisions API สำหรับสองขั้นตัดสินของการตามลูกค้า (การ์ด B3b, plan §3.5)
 *
 * สัญญา:
 * - POST เดียวต่อครั้ง ไป config('customer_follow_up.decisions_url') แบบ Bearer,
 *   connect timeout + total timeout จาก config — ไม่มี retry ไม่มี pool ไม่มีคำขอที่สอง
 * - fail closed: คะแนนต้องเป็นเลข JSON (int/float ไม่ใช่ string) ระหว่าง 0..1 และ
 *   choice อยู่ใน G1-G4 เท่านั้น — อย่างอื่น (non-2xx, connection, timeout, body ไม่ใช่
 *   JSON object) คืน null สำหรับ sort() และ error:true + failed:['check_error'] สำหรับ
 *   check() — ไม่ clamp ไม่ default คะแนน (D30 ไม่มี answers คืนจาก method)
 * - egress guard ก่อนยิงทุกครั้ง (D30): บริบทต้องเป็น list ไม่ว่าง ≤ MAX_LINES บรรทัด
 *   ทุกบรรทัดไม่มี account data และ redact(line) === line; check() ตรวจร่างด้วย
 * - log ล้มเหลว: warning เดียวต่อครั้ง context มีแค่ step + reason code
 *   (not_configured | unsafe_context | unsafe_draft | http_error | connection | invalid_response)
 *   — ไม่มีบริบท ร่าง body กุญแจ หรือข้อความ exception หลุดเข้า log
 *
 * Threshold อื่น (G1/G4, declined_threshold, automatic_min_confidence) เป็นหน้าที่ของ
 * การ์ด pipeline ไม่ใช่คลาสนี้
 */
class FollowUpDecisionsService
{
    /** reason codes ตายตัวทั้งหมดที่คลาสนี้เขียนลง log */
    public const REASON_NOT_CONFIGURED = 'not_configured';

    public const REASON_UNSAFE_CONTEXT = 'unsafe_context';

    public const REASON_UNSAFE_DRAFT = 'unsafe_draft';

    public const REASON_HTTP_ERROR = 'http_error';

    public const REASON_CONNECTION = 'connection';

    public const REASON_INVALID_RESPONSE = 'invalid_response';

    private const GROUPS = ['G1', 'G2', 'G3', 'G4'];

    /** เวลาเริ่ม request ล่าสุด (นับ latency) */
    private float $startedAt = 0.0;

    public function __construct(private OpenRouterCredentials $credentials) {}

    /**
     * ขั้นคัดกลุ่ม: กลุ่มลูกค้า (G1-G4), ความมั่นใจ, คะแนน "ปฏิเสธจะซื้อ"
     *
     * @param  list<string>  $redactedContext  บรรทัดบริบทที่ผ่าน redact แล้วจาก caller
     * @return array{group: string, confidence: float, declined: float, latency_ms: int, cost: ?float, usage: array{input_tokens: int, output_tokens: int, cost: ?float}}|null
     *                                                                                                                                                                         null = ยังไม่ตัดสินได้ — ห้ามร่างต่อ
     */
    public function sort(array $redactedContext, bool $boughtBefore, bool $transferSummarySent): ?array
    {
        $problem = $this->contextProblem($redactedContext);
        if ($problem !== null) {
            $this->logFailure('sort', $problem);

            return null;
        }

        $response = $this->post('sort', $redactedContext, [
            'model' => config('customer_follow_up.decisions_model'),
            'state' => [
                'shop' => FollowUpPrompts::shopSentence($redactedContext),
                'known_facts' => [
                    'bought_from_this_shop_before' => $boughtBefore,
                    'transfer_summary_was_sent' => $transferSummarySent,
                ],
                'conversation' => array_values($redactedContext),
            ],
            'questions' => FollowUpPrompts::sortQuestions(),
        ]);
        if ($response === null) {
            return null; // not configured / connection — logged already
        }

        $startedAt = $this->startedAt;
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $response->successful()) {
            $this->logFailure('sort', self::REASON_HTTP_ERROR);

            return null;
        }

        $data = $response->json();
        $answers = is_array($data) ? ($data['answers'] ?? null) : null;
        $groupAnswer = is_array($answers) ? ($answers['group'] ?? null) : null;
        $declinedAnswer = is_array($answers) ? ($answers['declined'] ?? null) : null;

        $choice = is_array($groupAnswer) ? ($groupAnswer['choice'] ?? null) : null;
        $confidence = is_array($groupAnswer) ? ($groupAnswer['confidence'] ?? null) : null;
        $declined = is_array($declinedAnswer) ? ($declinedAnswer['noul'] ?? null) : null;

        if (! is_string($choice) || ! in_array($choice, self::GROUPS, true)
            || ! self::isScore($confidence) || ! self::isScore($declined)) {
            $this->logFailure('sort', self::REASON_INVALID_RESPONSE);

            return null;
        }

        $usage = $this->usageFrom($response->json());

        return [
            'group' => $choice,
            'confidence' => (float) $confidence,
            'declined' => (float) $declined,
            'latency_ms' => $latencyMs,
            'cost' => $usage['cost'],
            'usage' => $usage,
        ];
    }

    /**
     * ขั้นตรวจร่าง: คะแนนหกข้อตามคำถาม — เกิน check_yes_threshold เข้า failed
     * ข้อผิดพลาดใด ๆ จะไม่มีวันกลายเป็น "ไม่มีปัญหา"
     *
     * @param  list<string>  $redactedContext
     * @param  string  $safeText  ร่างที่ผ่านตรวจปลอดภัยแล้วจาก FollowUpDraftService
     * @return array{scores: array<string, ?float>, failed: list<string>, error: bool, latency_ms: int, cost: ?float, usage: array{input_tokens: int, output_tokens: int, cost: ?float}}
     */
    public function check(array $redactedContext, string $group, string $safeText): array
    {
        $problem = $this->contextProblem($redactedContext) ?? $this->draftProblem($safeText);
        if ($problem !== null) {
            $this->logFailure('check', $problem);

            return $this->checkError(0);
        }

        $response = $this->post('check', $redactedContext, [
            'model' => config('customer_follow_up.decisions_model'),
            'state' => [
                'shop' => FollowUpPrompts::shopSentence($redactedContext),
                'conversation' => array_values($redactedContext),
                'group' => $group,
                'draft' => $safeText,
            ],
            'questions' => FollowUpPrompts::checkQuestions(),
        ]);
        if ($response === null) {
            return $this->checkError(0); // not configured / connection — logged already
        }

        $latencyMs = (int) round((microtime(true) - $this->startedAt) * 1000);

        if (! $response->successful()) {
            $this->logFailure('check', self::REASON_HTTP_ERROR);

            return $this->checkError($latencyMs);
        }

        $data = $response->json();
        $answers = is_array($data) ? ($data['answers'] ?? null) : null;
        if (! is_array($answers)) {
            $this->logFailure('check', self::REASON_INVALID_RESPONSE);

            return $this->checkError($latencyMs);
        }

        $threshold = (float) config('customer_follow_up.check_yes_threshold', 0.5);
        $scores = array_fill_keys(array_keys(FollowUpPrompts::checkQuestions()), null);
        $failed = [];
        $invalid = false;

        foreach (FollowUpPrompts::checkQuestions() as $key => $question) {
            $noul = is_array($answers[$key] ?? null) ? ($answers[$key]['noul'] ?? null) : null;
            if (! self::isScore($noul)) {
                $invalid = true;

                continue;
            }
            $score = (float) $noul;
            $scores[$key] = $score;
            if ($score >= $threshold) {
                $failed[] = $key;
            }
        }

        if ($invalid) {
            // error ไม่เคยเป็น all-clear — รายงาน check_error, คะแนนที่ตรวจได้ยังอ่านได้
            // (missing/invalid เป็น null) แต่รายชื่อข้อผ่านถูกทิ้งเพื่อไม่ให้ตีความครึ่ง ๆ
            $this->logFailure('check', self::REASON_INVALID_RESPONSE);
            $usage = $this->usageFrom($response->json());

            return [
                'scores' => $scores,
                'failed' => ['check_error'],
                'error' => true,
                'latency_ms' => $latencyMs,
                'cost' => $usage['cost'],
                'usage' => $usage,
            ];
        }

        $usage = $this->usageFrom($response->json());

        return [
            'scores' => $scores,
            'failed' => $failed,
            'error' => false,
            'latency_ms' => $latencyMs,
            'cost' => $usage['cost'],
            'usage' => $usage,
        ];
    }

    /**
     * POST เดียว — คืน null เมื่อยังไม่ตั้งค่าหรือ connection/timeout (log แล้ว)
     *
     * @param  list<string>  $redactedContext
     */
    private function post(string $step, array $redactedContext, array $body): ?Response
    {
        $url = (string) config('customer_follow_up.decisions_url', '');
        if (! $this->credentials->isConfigured() || $url === '') {
            $this->logFailure($step, self::REASON_NOT_CONFIGURED);

            return null;
        }

        $this->startedAt = microtime(true);

        try {
            return Http::withHeaders(['Authorization' => 'Bearer '.$this->credentials->key()])
                ->connectTimeout((int) config('customer_follow_up.http_connect_timeout_seconds', 3))
                ->timeout((int) config('customer_follow_up.decisions_timeout_seconds', 10))
                ->post($url, $body);
        } catch (ConnectionException) {
            $this->logFailure($step, self::REASON_CONNECTION);

            return null;
        }
    }

    /**
     * egress guard ฝั่งบริบท (D30) — คืน reason code เมื่อไม่ผ่าน, null เมื่อปลอดภัย
     *
     * @param  list<string>  $redactedContext
     */
    private function contextProblem(array $redactedContext): ?string
    {
        if ($redactedContext === [] || count($redactedContext) > FollowUpRedactor::MAX_LINES) {
            return self::REASON_UNSAFE_CONTEXT;
        }

        foreach ($redactedContext as $line) {
            if (! is_string($line)) {
                return self::REASON_UNSAFE_CONTEXT;
            }
            if (FollowUpRedactor::containsAccountData($line) || FollowUpRedactor::redact($line) !== $line) {
                return self::REASON_UNSAFE_CONTEXT;
            }
        }

        return null;
    }

    /** egress guard ฝั่งร่าง (D30) — คืน reason code เมื่อไม่ผ่าน, null เมื่อปลอดภัย */
    private function draftProblem(string $safeText): ?string
    {
        if (FollowUpRedactor::containsAccountData($safeText) || FollowUpRedactor::redact($safeText) !== $safeText) {
            return self::REASON_UNSAFE_DRAFT;
        }

        return null;
    }

    /** เลข JSON จริง (int/float ไม่ใช่ string/bool/null) ในช่วง 0..1 — ไม่ clamp */
    private static function isScore(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && $value >= 0 && $value <= 1;
    }

    /**
     * อ่าน usage จาก body — นับ token เป็น int (0 เมื่อขาด/ไม่ใช่เลข), cost ?float
     *
     * @return array{input_tokens: int, output_tokens: int, cost: ?float}
     */
    private function usageFrom(mixed $data): array
    {
        $usage = is_array($data) ? ($data['usage'] ?? []) : [];
        $tokens = fn (mixed $value): int => is_int($value) ? $value : (is_float($value) ? (int) $value : 0);
        $cost = is_array($usage) ? ($usage['cost'] ?? null) : null;

        return [
            'input_tokens' => $tokens(is_array($usage) ? ($usage['input_tokens'] ?? null) : null),
            'output_tokens' => $tokens(is_array($usage) ? ($usage['output_tokens'] ?? null) : null),
            'cost' => is_int($cost) || is_float($cost) ? (float) $cost : null,
        ];
    }

    /** ผลลัพธ์ error ของ check() — ไม่มีวันเป็น all-clear */
    private function checkError(int $latencyMs): array
    {
        $scores = array_fill_keys(array_keys(FollowUpPrompts::checkQuestions()), null);

        return [
            'scores' => $scores,
            'failed' => ['check_error'],
            'error' => true,
            'latency_ms' => $latencyMs,
            'cost' => null,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'cost' => null],
        ];
    }

    /** warning เดียว มีแค่ step + reason — ไม่มีข้อมูลอื่นหลุดเข้า log */
    private function logFailure(string $step, string $reason): void
    {
        Log::warning('Follow-up decisions call failed', [
            'step' => $step,
            'reason' => $reason,
        ]);
    }
}
