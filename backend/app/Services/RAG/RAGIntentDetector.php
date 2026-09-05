<?php

namespace App\Services\RAG;

/**
 * Message classifiers split out of RAGService (Track 3). Bodies are moved
 * verbatim; no injected dependencies (reads config only).
 */
class RAGIntentDetector
{
    private const SIMPLE_MESSAGE_PATTERN = '/^(สวัสดี|หวัดดี|ดี(ครับ|ค่ะ)?|ขอบคุณ|ขอบใจ|บาย|ลาก่อน|ok|oke|โอเค|hi|hello|hey|thanks|thank you|bye|good\s?(morning|evening|night))$/iu';

    /**
     * Whether a message is a trivial greeting/acknowledgement that needs neither
     * a decision-model round-trip nor a KB lookup (always resolves to 'chat').
     */
    public function isSimpleMessage(string $userMessage): bool
    {
        return mb_strlen($userMessage) <= 30
            && (bool) preg_match(self::SIMPLE_MESSAGE_PATTERN, trim($userMessage));
    }

    /**
     * Detect if a user message requires complex reasoning (Chain-of-Thought).
     *
     * Uses heuristics-based detection to avoid additional LLM calls.
     * Returns complexity score and reasons for activation.
     *
     * @param  string  $userMessage  The user's message
     * @return array{is_complex: bool, score: int, reasons: array}
     */
    public function detectComplexity(string $userMessage): array
    {
        $score = 0;
        $reasons = [];
        $threshold = config('rag.chain_of_thought.complexity_threshold', 2);

        // Greeting patterns — should NOT trigger agent loop
        $greetingPatterns = [
            '/^(สวัสดี|หวัดดี|ดีครับ|ดีค่ะ|ดีจ้า|ดีจ้ะ|ดี|hello|hi|hey|yo|good\s*(morning|afternoon|evening))[\s!\.]*$/iu',
        ];

        foreach ($greetingPatterns as $pattern) {
            if (preg_match($pattern, trim($userMessage))) {
                return ['is_complex' => false, 'score' => 0, 'reasons' => ['greeting_detected']];
            }
        }

        // 1. Message length > 100 characters (indicates detailed question)
        if (mb_strlen($userMessage) > 100) {
            $score += 1;
            $reasons[] = 'long_message';
        }

        // 2. Multiple questions (multiple question marks)
        $questionMarkCount = substr_count($userMessage, '?');
        if ($questionMarkCount > 1) {
            $score += 2;
            $reasons[] = 'multiple_questions';
        }

        // 3. Reasoning keywords that require step-by-step thinking
        $reasoningKeywords = [
            // English
            'compare', 'comparison', 'versus', 'vs',
            'analyze', 'analysis', 'evaluate', 'assessment',
            'why', 'how come', 'reason',
            'explain', 'elaborate', 'describe in detail',
            'pros and cons', 'advantages and disadvantages',
            'step by step', 'steps to', 'process',
            'calculate', 'compute', 'solve',
            'if', 'assuming', 'suppose', 'what if',
            'difference between', 'similarities',
            'best', 'recommend', 'suggest', 'which one',
            // Thai
            'เปรียบเทียบ', 'เทียบกับ',
            'วิเคราะห์', 'ประเมิน',
            'ทำไม', 'เพราะอะไร', 'สาเหตุ',
            'อธิบาย', 'ขยายความ',
            'ข้อดีข้อเสีย', 'ข้อดี', 'ข้อเสีย',
            'ทีละขั้นตอน', 'ขั้นตอน', 'วิธีการ',
            'คำนวณ', 'หาค่า',
            'ถ้า', 'สมมติ', 'หาก',
            'ความแตกต่าง', 'ต่างกันยังไง',
            'ดีที่สุด', 'แนะนำ', 'เลือกอันไหน',
        ];

        $lowerMessage = mb_strtolower($userMessage);
        foreach ($reasoningKeywords as $keyword) {
            if (mb_stripos($lowerMessage, $keyword) !== false) {
                $score += 2;
                $reasons[] = "keyword:{$keyword}";
                break; // Only count once for keywords
            }
        }

        // 4. Contains numbers with operations (likely calculation)
        if (preg_match('/\d+\s*[\+\-\*\/\%]\s*\d+/', $userMessage)) {
            $score += 1;
            $reasons[] = 'contains_calculation';
        }

        // 5. Contains list indicators (enumeration questions)
        if (preg_match('/\d+[\.\)]\s|\b(first|second|third|firstly|secondly|อันดับ|ประการ)\b/i', $userMessage)) {
            $score += 1;
            $reasons[] = 'enumeration';
        }

        return [
            'is_complex' => $score >= $threshold,
            'score' => $score,
            'reasons' => $reasons,
        ];
    }

    /**
     * Detect if user message explicitly requires a tool.
     *
     * @param  string  $userMessage  User's message to analyze
     * @param  array  $enabledTools  List of enabled tool names for this flow
     * @return array{needs_tool: bool, tool_hint: ?string, reasons: array}
     */
    public function detectToolIntent(string $userMessage, array $enabledTools = []): array
    {
        $lowerMessage = mb_strtolower($userMessage);
        $reasons = [];
        $toolHint = null;

        // Calculate tool
        if (in_array('calculate', $enabledTools)) {
            $calcKeywords = ['คำนวณ', 'คิดราคา', 'คิดเงิน', 'รวมราคา', 'ส่วนลด', 'กี่บาท',
                'calculate', 'total', 'discount'];
            foreach ($calcKeywords as $kw) {
                if (mb_stripos($lowerMessage, $kw) !== false) {
                    $reasons[] = "tool_keyword:{$kw}";
                    $toolHint = 'calculate';
                    break;
                }
            }
            // Arithmetic expressions
            if (preg_match('/\d+\s*[\+\-\*\/\%x]\s*\d+/', $userMessage)) {
                $reasons[] = 'arithmetic_expression';
                $toolHint = $toolHint ?? 'calculate';
            }
        }

        // Datetime tool
        if (in_array('get_current_datetime', $enabledTools) && ! $toolHint) {
            $datetimeKeywords = ['วันนี้', 'เวลา', 'กี่โมง', 'วันที่', 'วันอะไร', 'what time', 'what day', 'today', 'date', 'current time'];
            foreach ($datetimeKeywords as $kw) {
                if (mb_stripos($lowerMessage, $kw) !== false) {
                    $reasons[] = "tool_keyword:{$kw}";
                    $toolHint = 'get_current_datetime';
                    break;
                }
            }
        }

        // Escalate tool
        if (in_array('escalate_to_human', $enabledTools) && ! $toolHint) {
            $escalateKeywords = ['คุยกับคน', 'ติดต่อพนักงาน', 'ขอคุยกับเจ้าหน้าที่', 'talk to human', 'real person', 'human agent', 'speak to someone'];
            foreach ($escalateKeywords as $kw) {
                if (mb_stripos($lowerMessage, $kw) !== false) {
                    $reasons[] = "tool_keyword:{$kw}";
                    $toolHint = 'escalate_to_human';
                    break;
                }
            }
        }

        // Think tool (complex analysis)
        if (in_array('think', $enabledTools) && ! $toolHint) {
            $thinkKeywords = ['วิเคราะห์เชิงลึก', 'เปรียบเทียบทุกตัว', 'สรุปให้'];
            foreach ($thinkKeywords as $kw) {
                if (mb_stripos($lowerMessage, $kw) !== false) {
                    $reasons[] = "tool_keyword:{$kw}";
                    $toolHint = 'think';
                    break;
                }
            }
        }

        return [
            'needs_tool' => ! empty($reasons),
            'tool_hint' => $toolHint,
            'reasons' => $reasons,
        ];
    }

    /**
     * Detect the primary language of a message.
     *
     * Simple detection based on Thai character presence.
     *
     * @param  string  $message  The message to analyze
     * @return string 'thai' or 'english'
     */
    public function detectLanguage(string $message): string
    {
        // Count Thai characters (Unicode range: \x{0E00}-\x{0E7F})
        $thaiCharCount = preg_match_all('/[\x{0E00}-\x{0E7F}]/u', $message);

        // If more than 20% Thai characters, consider it Thai
        $totalChars = mb_strlen($message);
        if ($totalChars > 0 && ($thaiCharCount / $totalChars) > 0.2) {
            return 'thai';
        }

        return 'english';
    }
}
