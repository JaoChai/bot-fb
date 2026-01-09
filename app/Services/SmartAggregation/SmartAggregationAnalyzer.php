<?php

namespace App\Services\SmartAggregation;

use App\Models\Bot;
use Illuminate\Support\Facades\Log;

/**
 * Main service for smart aggregation analysis.
 * Provides adaptive wait time calculation and early trigger detection.
 */
class SmartAggregationAnalyzer
{
    public function __construct(
        protected ?UserTypingStats $userTypingStats = null
    ) {}

    /**
     * Check if smart aggregation is enabled for a bot.
     */
    public function isSmartEnabled(?Bot $bot): bool
    {
        if ($bot === null) {
            return false;
        }

        $settings = $bot->settings;

        // Must have base aggregation enabled first
        if (!$settings?->wait_multiple_bubbles_enabled) {
            return false;
        }

        return $settings->smart_aggregation_enabled ?? false;
    }

    /**
     * Calculate adaptive wait time based on context.
     */
    public function calculateAdaptiveWaitTime(AggregationContext $context): int
    {
        $settings = $context->bot?->settings;

        // If smart not enabled, return fixed wait time
        if (!$this->isSmartEnabled($context->bot)) {
            return $context->baseWaitMs;
        }

        // Check per-user learning first (Phase 4)
        if ($settings?->smart_per_user_learning_enabled && $context->customerId && $context->bot) {
            $personalizedWait = $this->userTypingStats?->getRecommendedWaitTime(
                $context->bot->id,
                $context->customerId
            );

            if ($personalizedWait !== null) {
                Log::debug('Using personalized wait time', [
                    'bot_id' => $context->bot->id,
                    'customer_id' => $context->customerId,
                    'wait_ms' => $personalizedWait,
                ]);
                return $personalizedWait;
            }
        }

        // Calculate adaptive wait time
        return $this->calculateBaseAdaptiveWait($context);
    }

    /**
     * Calculate adaptive wait time from message gaps.
     */
    protected function calculateBaseAdaptiveWait(AggregationContext $context): int
    {
        $settings = $context->bot?->settings;
        $minWait = $settings?->smart_min_wait_ms ?? 500;
        $maxWait = $settings?->smart_max_wait_ms ?? 3000;
        $baseWait = $context->baseWaitMs;

        // If only one message or no gap data, use base wait
        if ($context->messageCount <= 1 || $context->avgGapMs <= 0) {
            return $baseWait;
        }

        // Formula: wait = avg_gap * 1.5 (50% buffer for typing)
        $adaptiveWait = (int) ($context->avgGapMs * 1.5);

        // Apply multiplier based on message count (more messages = user is active = shorter wait)
        $countMultiplier = max(0.7, 1 - ($context->messageCount * 0.1));
        $adaptiveWait = (int) ($adaptiveWait * $countMultiplier);

        // Clamp to bounds
        $finalWait = max($minWait, min($maxWait, $adaptiveWait));

        Log::debug('Calculated adaptive wait time', [
            'avg_gap_ms' => $context->avgGapMs,
            'message_count' => $context->messageCount,
            'count_multiplier' => $countMultiplier,
            'adaptive_wait' => $adaptiveWait,
            'final_wait' => $finalWait,
        ]);

        return $finalWait;
    }

    /**
     * Determine if we should trigger response immediately (skip aggregation wait).
     */
    public function shouldTriggerEarly(string $content, AggregationContext $context): bool
    {
        $settings = $context->bot?->settings;

        // Skip if smart not enabled
        if (!$this->isSmartEnabled($context->bot)) {
            return false;
        }

        // Skip if early trigger disabled
        if (!($settings?->smart_early_trigger_enabled ?? true)) {
            return false;
        }

        $analysis = $this->analyzeMessage($content);

        // Trigger immediately for greetings (first message only)
        if ($analysis->isGreeting && $context->messageCount === 1) {
            Log::debug('Early trigger: greeting detected', ['content' => $content]);
            return true;
        }

        // Trigger for complete questions (first message with high completeness)
        if ($analysis->isQuestion && $analysis->completenessScore >= 0.8 && $context->messageCount === 1) {
            Log::debug('Early trigger: complete question detected', [
                'content' => $content,
                'score' => $analysis->completenessScore,
            ]);
            return true;
        }

        // Trigger if message is clearly complete AND it's the first message
        if ($context->messageCount === 1 && $analysis->completenessScore >= 0.85) {
            Log::debug('Early trigger: high completeness score', [
                'content' => $content,
                'score' => $analysis->completenessScore,
            ]);
            return true;
        }

        return false;
    }

    /**
     * Analyze a message for completeness, type, etc.
     */
    public function analyzeMessage(string $content): MessageAnalysis
    {
        $content = trim($content);
        $completeness = $this->detectMessageCompleteness($content);

        $isThaiText = ThaiLanguagePatterns::isThaiText($content);
        $endMarker = null;

        // Detect end marker
        if ($isThaiText) {
            $endMarker = ThaiLanguagePatterns::detectEndParticle($content);
        }
        if (!$endMarker) {
            $lastChar = mb_substr($content, -1);
            if (in_array($lastChar, ['.', '!', '?'])) {
                $endMarker = $lastChar;
            }
        }

        return new MessageAnalysis(
            isComplete: $completeness['score'] >= 0.7,
            isGreeting: ThaiLanguagePatterns::isGreeting($content),
            isQuestion: ThaiLanguagePatterns::isQuestion($content),
            hasContinuationHint: ThaiLanguagePatterns::hasContinuationMarker($content),
            completenessScore: $completeness['score'],
            detectedLanguage: $isThaiText ? 'th' : 'en',
            endMarker: $endMarker,
        );
    }

    /**
     * Detect message completeness and return score with reason.
     */
    protected function detectMessageCompleteness(string $content): array
    {
        $content = trim($content);
        $score = 0.5; // neutral starting point
        $reason = 'neutral';
        $length = mb_strlen($content);

        // Check for Thai end particles (strong signal)
        $thaiEndParticle = ThaiLanguagePatterns::detectEndParticle($content);
        if ($thaiEndParticle) {
            $score = 0.9;
            $reason = "ends with Thai particle: {$thaiEndParticle}";
        }

        // Check for English end markers
        $lastChar = mb_substr($content, -1);
        if (in_array($lastChar, ['.', '!', '?'])) {
            $score = max($score, 0.85);
            $reason = "ends with punctuation: {$lastChar}";
        }

        // Check for continuation markers (reduces score)
        if (ThaiLanguagePatterns::hasContinuationMarker($content)) {
            $score = min($score, 0.3);
            $reason = 'has continuation marker';
        }

        // Very short messages likely incomplete (unless greeting)
        if ($length < 5 && !ThaiLanguagePatterns::isGreeting($content)) {
            $score = min($score, 0.4);
            $reason = 'very short message';
        }

        // Long messages with no end marker - moderate score
        if ($length > 50 && $score < 0.6) {
            $score = max($score, 0.6);
            $reason = 'long message without end marker';
        }

        return [
            'score' => $score,
            'reason' => $reason,
        ];
    }
}
