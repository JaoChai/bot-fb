<?php

namespace App\Services\SecondAI;

use App\Models\SecondAILog;
use Illuminate\Support\Facades\Log;

/**
 * SecondAIMetricsService - Extracts and logs metrics from Second AI checks
 *
 * Responsibilities:
 * - Extract quantitative scores from check results
 * - Calculate overall quality score
 * - Log metrics to database for dashboard analysis
 * - Provide score aggregation for analytics
 */
class SecondAIMetricsService
{
    /**
     * Extract scores from a SecondAICheckResult
     *
     * @param SecondAICheckResult $result Check result to extract scores from
     * @return array Extracted scores with groundedness, policy, and personality
     */
    public function extractScores(SecondAICheckResult $result): array
    {
        $scores = [
            'groundedness' => null,
            'policy_compliance' => null,
            'personality_match' => null,
        ];

        // Extract fact check score (groundedness)
        if (isset($result->modifications['fact_check'])) {
            $scores['groundedness'] = $this->calculateGroundednessScore(
                $result->modifications['fact_check']
            );
        }

        // Extract policy check score
        if (isset($result->modifications['policy'])) {
            $scores['policy_compliance'] = $this->calculatePolicyScore(
                $result->modifications['policy']
            );
        }

        // Extract personality check score
        if (isset($result->modifications['personality'])) {
            $scores['personality_match'] = $this->calculatePersonalityScore(
                $result->modifications['personality']
            );
        }

        // Calculate overall score
        $scores['overall'] = SecondAILog::calculateOverallScore(
            $scores['groundedness'],
            $scores['policy_compliance'],
            $scores['personality_match']
        );

        return $scores;
    }

    /**
     * Calculate groundedness score from fact check result
     *
     * Score logic:
     * - No modifications needed = 1.0 (perfect)
     * - Modifications needed: score based on ratio of verified claims
     *
     * @param array $factCheckResult Fact check modifications
     * @return float Score from 0.0 to 1.0
     */
    public function calculateGroundednessScore(array $factCheckResult): float
    {
        // If no modification required, perfect score
        if (!($factCheckResult['required'] ?? false)) {
            return 1.0;
        }

        $claimsExtracted = $factCheckResult['claims_extracted'] ?? [];
        $unverifiedClaims = $factCheckResult['unverified_claims'] ?? [];

        $totalClaims = count($claimsExtracted);
        $unverifiedCount = count($unverifiedClaims);

        if ($totalClaims === 0) {
            // No claims to verify = consider it grounded
            return 1.0;
        }

        // Score is ratio of verified claims
        $verifiedCount = $totalClaims - $unverifiedCount;
        return max(0.0, round($verifiedCount / $totalClaims, 2));
    }

    /**
     * Calculate policy compliance score from policy check result
     *
     * Score logic:
     * - No violations = 1.0 (perfect)
     * - Violations found: penalize based on count
     *
     * @param array $policyResult Policy check modifications
     * @return float Score from 0.0 to 1.0
     */
    public function calculatePolicyScore(array $policyResult): float
    {
        // If no modification required, perfect score
        if (!($policyResult['required'] ?? false)) {
            return 1.0;
        }

        $violations = $policyResult['violations'] ?? [];
        $violationCount = count($violations);

        if ($violationCount === 0) {
            return 1.0;
        }

        // Score decreases with more violations
        // 1 violation = 0.7, 2 violations = 0.5, 3+ violations = 0.3
        return match (true) {
            $violationCount === 1 => 0.7,
            $violationCount === 2 => 0.5,
            default => 0.3,
        };
    }

    /**
     * Calculate personality match score from personality check result
     *
     * Score logic:
     * - No issues = 1.0 (perfect)
     * - Issues found: penalize based on severity
     *
     * @param array $personalityResult Personality check modifications
     * @return float Score from 0.0 to 1.0
     */
    public function calculatePersonalityScore(array $personalityResult): float
    {
        // If no modification required, perfect score
        if (!($personalityResult['required'] ?? false)) {
            return 1.0;
        }

        $issues = $personalityResult['issues'] ?? [];
        $issueCount = count($issues);

        if ($issueCount === 0) {
            return 1.0;
        }

        // Score decreases with more issues
        // 1 issue = 0.8, 2 issues = 0.6, 3+ issues = 0.4
        return match (true) {
            $issueCount === 1 => 0.8,
            $issueCount === 2 => 0.6,
            default => 0.4,
        };
    }

    /**
     * Log metrics to database
     *
     * @param int $botId Bot ID
     * @param int $flowId Flow ID
     * @param SecondAICheckResult $result Check result
     * @param int|null $conversationId Optional conversation ID
     * @param int|null $messageId Optional message ID
     * @param string $executionMode Execution mode (unified/sequential)
     * @return SecondAILog|null Created log entry or null on failure
     */
    public function logMetrics(
        int $botId,
        int $flowId,
        SecondAICheckResult $result,
        ?int $conversationId = null,
        ?int $messageId = null,
        string $executionMode = 'unified'
    ): ?SecondAILog {
        try {
            $scores = $this->extractScores($result);

            return SecondAILog::create([
                'bot_id' => $botId,
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'flow_id' => $flowId,
                'groundedness_score' => $scores['groundedness'],
                'policy_compliance_score' => $scores['policy_compliance'],
                'personality_match_score' => $scores['personality_match'],
                'overall_score' => $scores['overall'],
                'was_modified' => !$result->passed,
                'checks_applied' => $result->getAppliedChecks(),
                'modifications' => $result->modifications,
                'latency_ms' => $result->metadata['latency_ms'] ?? null,
                'model_used' => $result->metadata['model_used'] ?? null,
                'execution_mode' => $executionMode,
            ]);
        } catch (\Exception $e) {
            Log::error('SecondAIMetricsService: Failed to log metrics', [
                'bot_id' => $botId,
                'flow_id' => $flowId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Log metrics from legacy format result array
     *
     * @param int $botId Bot ID
     * @param int $flowId Flow ID
     * @param array $result Legacy format result from SecondAIService
     * @param int|null $conversationId Optional conversation ID
     * @param int|null $messageId Optional message ID
     * @param string $executionMode Execution mode
     * @return SecondAILog|null
     */
    public function logMetricsFromLegacy(
        int $botId,
        int $flowId,
        array $result,
        ?int $conversationId = null,
        ?int $messageId = null,
        string $executionMode = 'sequential'
    ): ?SecondAILog {
        try {
            $secondAi = $result['second_ai'] ?? [];
            $modifications = $secondAi['modifications'] ?? [];
            $checksApplied = $secondAi['checks_applied'] ?? [];

            // Calculate scores from modifications
            $groundedness = null;
            $policy = null;
            $personality = null;

            if (isset($modifications['fact_check'])) {
                $groundedness = $this->calculateGroundednessScore($modifications['fact_check']);
            }
            if (isset($modifications['policy'])) {
                $policy = $this->calculatePolicyScore($modifications['policy']);
            }
            if (isset($modifications['personality'])) {
                $personality = $this->calculatePersonalityScore($modifications['personality']);
            }

            // If checks were applied but no modifications, give perfect scores
            if (in_array('fact_check', $checksApplied) && $groundedness === null) {
                $groundedness = 1.0;
            }
            if (in_array('policy', $checksApplied) && $policy === null) {
                $policy = 1.0;
            }
            if (in_array('personality', $checksApplied) && $personality === null) {
                $personality = 1.0;
            }

            $overall = SecondAILog::calculateOverallScore($groundedness, $policy, $personality);

            return SecondAILog::create([
                'bot_id' => $botId,
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'flow_id' => $flowId,
                'groundedness_score' => $groundedness,
                'policy_compliance_score' => $policy,
                'personality_match_score' => $personality,
                'overall_score' => $overall,
                'was_modified' => $result['second_ai_applied'] ?? false,
                'checks_applied' => $checksApplied,
                'modifications' => $modifications,
                'latency_ms' => $secondAi['elapsed_ms'] ?? null,
                'model_used' => $secondAi['model_used'] ?? null,
                'execution_mode' => $executionMode,
            ]);
        } catch (\Exception $e) {
            Log::error('SecondAIMetricsService: Failed to log legacy metrics', [
                'bot_id' => $botId,
                'flow_id' => $flowId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
