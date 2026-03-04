<?php

namespace App\Services\SecondAI;

use App\Models\InjectionAttemptLog;
use Illuminate\Support\Facades\Log;

/**
 * PromptInjectionDetector - Detects and blocks prompt injection attacks
 *
 * Implements pattern-based detection for common jailbreak attempts:
 * - English patterns: ignore instructions, system prompt override, etc.
 * - Thai patterns: ลืมคำสั่ง, เปลี่ยนคำสั่ง, etc.
 * - Encoding attacks: base64, eval
 *
 * Risk scoring (0.0-1.0) enables flexible response strategies:
 * - High risk (>=0.8): Block immediately
 * - Medium risk (>=0.5): Flag for review
 * - Low risk (<0.5): Allow with logging
 */
class PromptInjectionDetector
{
    /**
     * Risk threshold for blocking (0.0-1.0)
     */
    protected float $blockThreshold = 0.7;

    /**
     * Risk threshold for flagging (0.0-1.0)
     */
    protected float $flagThreshold = 0.4;

    /**
     * English injection patterns with risk weights
     */
    protected array $englishPatterns = [
        // System prompt manipulation
        'ignore previous' => 0.9,
        'ignore all instructions' => 0.95,
        'ignore your instructions' => 0.9,
        'disregard previous' => 0.9,
        'disregard all instructions' => 0.95,
        'forget everything' => 0.85,
        'forget your instructions' => 0.9,
        'forget all previous' => 0.9,
        'new instructions' => 0.7,
        'override instructions' => 0.9,
        'bypass restrictions' => 0.95,

        // Role manipulation
        'you are now' => 0.7,
        'act as' => 0.5,
        'pretend to be' => 0.6,
        'roleplay as' => 0.5,
        'assume the role' => 0.6,

        // Jailbreak attempts
        'jailbreak' => 0.95,
        'dan mode' => 0.95,
        'developer mode' => 0.9,
        'god mode' => 0.9,
        'unrestricted mode' => 0.9,
        'no restrictions' => 0.85,
        'without restrictions' => 0.85,

        // System prompt extraction
        'system prompt' => 0.7,
        'reveal your prompt' => 0.85,
        'show your instructions' => 0.8,
        'what are your instructions' => 0.7,
        'print your prompt' => 0.85,

        // Prompt injection markers
        '###' => 0.3,
        '```' => 0.2,
        '[system]' => 0.7,
        '<system>' => 0.7,
        '{system}' => 0.7,
    ];

    /**
     * Thai injection patterns with risk weights
     */
    protected array $thaiPatterns = [
        // System prompt manipulation
        'ลืมคำสั่งก่อนหน้า' => 0.9,
        'ลืมคำสั่งเดิม' => 0.9,
        'เพิกเฉยคำสั่ง' => 0.85,
        'ไม่ต้องสนใจคำสั่ง' => 0.85,
        'เปลี่ยนคำสั่ง' => 0.8,
        'คำสั่งใหม่' => 0.7,
        'แก้ไขคำสั่ง' => 0.75,

        // Role manipulation
        'แกล้งทำเป็น' => 0.7,
        'สมมติว่าเป็น' => 0.6,
        'เล่นเป็น' => 0.5,
        'ทำตัวเป็น' => 0.5,

        // Jailbreak attempts
        'ปลดล็อค' => 0.8,
        'ไม่มีข้อจำกัด' => 0.85,
        'ไร้ขีดจำกัด' => 0.85,
        'โหมดพัฒนา' => 0.8,
        'โหมดไม่จำกัด' => 0.9,

        // System prompt extraction
        'แสดงคำสั่งระบบ' => 0.8,
        'บอกคำสั่งที่ได้รับ' => 0.75,
        'prompt ของคุณ' => 0.7,
    ];

    /**
     * Encoding-based attack patterns
     */
    protected array $encodingPatterns = [
        'base64:' => 0.8,
        'atob(' => 0.7,
        'btoa(' => 0.5,
        '\\x' => 0.6,  // Hex escape
        '\\u' => 0.5,  // Unicode escape
        'fromCharCode' => 0.7,
    ];

    /**
     * Detect prompt injection in user input
     *
     * @param  string  $input  User input to check
     * @return DetectionResult Detection result with risk score and matched patterns
     */
    public function detect(string $input): DetectionResult
    {
        $normalizedInput = $this->normalizeInput($input);
        $matches = [];
        $maxRisk = 0.0;

        // Check English patterns
        foreach ($this->englishPatterns as $pattern => $risk) {
            if (stripos($normalizedInput, $pattern) !== false) {
                $matches[] = ['pattern' => $pattern, 'risk' => $risk, 'category' => 'english'];
                $maxRisk = max($maxRisk, $risk);
            }
        }

        // Check Thai patterns
        foreach ($this->thaiPatterns as $pattern => $risk) {
            if (mb_stripos($normalizedInput, $pattern) !== false) {
                $matches[] = ['pattern' => $pattern, 'risk' => $risk, 'category' => 'thai'];
                $maxRisk = max($maxRisk, $risk);
            }
        }

        // Check encoding patterns
        foreach ($this->encodingPatterns as $pattern => $risk) {
            if (stripos($normalizedInput, $pattern) !== false) {
                $matches[] = ['pattern' => $pattern, 'risk' => $risk, 'category' => 'encoding'];
                $maxRisk = max($maxRisk, $risk);
            }
        }

        // Calculate combined risk score
        $riskScore = $this->calculateRiskScore($matches, $maxRisk);

        // Determine action
        $action = $this->determineAction($riskScore);

        return new DetectionResult(
            detected: ! empty($matches),
            riskScore: $riskScore,
            patterns: $matches,
            action: $action,
            message: $this->getActionMessage($action)
        );
    }

    /**
     * Normalize input for pattern matching
     */
    protected function normalizeInput(string $input): string
    {
        // Convert to lowercase for case-insensitive matching
        $normalized = mb_strtolower($input);

        // Remove excessive whitespace
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        // Decode common obfuscation attempts
        $normalized = $this->decodeObfuscation($normalized);

        return trim($normalized);
    }

    /**
     * Decode common obfuscation techniques
     */
    protected function decodeObfuscation(string $input): string
    {
        // Decode URL encoding
        $decoded = urldecode($input);

        // Decode HTML entities
        $decoded = html_entity_decode($decoded);

        // Remove zero-width characters
        $decoded = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $decoded);

        // Normalize Unicode characters
        if (function_exists('normalizer_normalize')) {
            $decoded = normalizer_normalize($decoded, \Normalizer::FORM_C) ?: $decoded;
        }

        return $decoded;
    }

    /**
     * Calculate combined risk score from matches
     */
    protected function calculateRiskScore(array $matches, float $maxRisk): float
    {
        if (empty($matches)) {
            return 0.0;
        }

        // Use max risk as base, with small bonus for multiple matches
        $matchCount = count($matches);
        $multiMatchBonus = min(0.1, ($matchCount - 1) * 0.02);

        return min(1.0, $maxRisk + $multiMatchBonus);
    }

    /**
     * Determine action based on risk score
     */
    protected function determineAction(float $riskScore): string
    {
        if ($riskScore >= $this->blockThreshold) {
            return 'blocked';
        }

        if ($riskScore >= $this->flagThreshold) {
            return 'flagged';
        }

        return 'allowed';
    }

    /**
     * Get human-readable message for action
     */
    protected function getActionMessage(string $action): string
    {
        return match ($action) {
            'blocked' => 'ตรวจพบข้อความที่อาจเป็นอันตราย กรุณาส่งข้อความใหม่',
            'flagged' => 'ข้อความถูกตรวจสอบเพิ่มเติม',
            default => '',
        };
    }

    /**
     * Check if input should be blocked
     */
    public function shouldBlock(string $input): bool
    {
        return $this->detect($input)->action === 'blocked';
    }

    /**
     * Log injection attempt to database
     *
     * @param  int  $botId  Bot ID
     * @param  string  $input  Original user input
     * @param  DetectionResult  $result  Detection result
     * @param  int|null  $conversationId  Optional conversation ID
     */
    public function log(
        int $botId,
        string $input,
        DetectionResult $result,
        ?int $conversationId = null
    ): ?InjectionAttemptLog {
        // Only log if something was detected
        if (! $result->detected) {
            return null;
        }

        try {
            return InjectionAttemptLog::create([
                'bot_id' => $botId,
                'conversation_id' => $conversationId,
                'user_input' => mb_substr($input, 0, 5000), // Truncate long inputs
                'detected_patterns' => $result->patterns,
                'risk_score' => $result->riskScore,
                'action_taken' => $result->action,
            ]);
        } catch (\Exception $e) {
            Log::error('PromptInjectionDetector: Failed to log attempt', [
                'bot_id' => $botId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Set block threshold
     */
    public function setBlockThreshold(float $threshold): self
    {
        $this->blockThreshold = max(0.0, min(1.0, $threshold));

        return $this;
    }

    /**
     * Set flag threshold
     */
    public function setFlagThreshold(float $threshold): self
    {
        $this->flagThreshold = max(0.0, min(1.0, $threshold));

        return $this;
    }

    /**
     * Add custom pattern
     */
    public function addPattern(string $pattern, float $risk, string $category = 'custom'): self
    {
        if ($category === 'thai') {
            $this->thaiPatterns[$pattern] = $risk;
        } elseif ($category === 'encoding') {
            $this->encodingPatterns[$pattern] = $risk;
        } else {
            $this->englishPatterns[$pattern] = $risk;
        }

        return $this;
    }
}
