<?php

namespace App\Services\QAInspector;

use App\Models\Flow;
use App\Models\QAWeeklyReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class PromptSuggestionApplier
{
    /**
     * Validation error codes
     */
    public const ERROR_REPORT_NOT_COMPLETED = 'report_not_completed';
    public const ERROR_INVALID_INDEX = 'invalid_index';
    public const ERROR_NO_SUGGESTIONS = 'no_suggestions';
    public const ERROR_ALREADY_APPLIED = 'already_applied';
    public const ERROR_EMPTY_BEFORE_AFTER = 'empty_before_after';
    public const ERROR_FLOW_BOT_MISMATCH = 'flow_bot_mismatch';
    public const ERROR_BEFORE_NOT_FOUND = 'before_not_found';
    public const ERROR_PROMPT_MODIFIED = 'prompt_modified';

    /**
     * Validate that a suggestion can be applied
     *
     * @return array{valid: bool, error?: string, error_code?: string, expected?: string, actual?: string, can_force?: bool}
     */
    public function validateSuggestion(QAWeeklyReport $report, int $suggestionIndex, Flow $flow): array
    {
        // Check report is completed
        if (!$report->isCompleted()) {
            return [
                'valid' => false,
                'error' => 'Report is not completed',
                'error_code' => self::ERROR_REPORT_NOT_COMPLETED,
            ];
        }

        // Check suggestions exist
        $suggestions = $report->prompt_suggestions;
        if (empty($suggestions)) {
            return [
                'valid' => false,
                'error' => 'No prompt suggestions in this report',
                'error_code' => self::ERROR_NO_SUGGESTIONS,
            ];
        }

        // Check index is valid
        if ($suggestionIndex < 0 || $suggestionIndex >= count($suggestions)) {
            return [
                'valid' => false,
                'error' => "Invalid suggestion index: {$suggestionIndex}. Valid range: 0-" . (count($suggestions) - 1),
                'error_code' => self::ERROR_INVALID_INDEX,
            ];
        }

        $suggestion = $suggestions[$suggestionIndex];

        // Check suggestion is not already applied
        if (!empty($suggestion['applied'])) {
            return [
                'valid' => false,
                'error' => 'This suggestion has already been applied',
                'error_code' => self::ERROR_ALREADY_APPLIED,
                'applied_at' => $suggestion['applied_at'] ?? null,
            ];
        }

        // Check before/after are present
        if (empty($suggestion['before']) || empty($suggestion['after'])) {
            return [
                'valid' => false,
                'error' => 'Suggestion is missing before or after text',
                'error_code' => self::ERROR_EMPTY_BEFORE_AFTER,
            ];
        }

        // Check flow belongs to the same bot
        if ($flow->bot_id !== $report->bot_id) {
            return [
                'valid' => false,
                'error' => 'Flow does not belong to the same bot as the report',
                'error_code' => self::ERROR_FLOW_BOT_MISMATCH,
            ];
        }

        // Check that 'before' text exists in the prompt
        $currentPrompt = $flow->system_prompt ?? '';
        $beforeText = $suggestion['before'];

        if (strpos($currentPrompt, $beforeText) === false) {
            // The 'before' text is not found - could be a conflict
            return [
                'valid' => false,
                'error' => 'The expected text was not found in the current prompt. The prompt may have been modified since the report was generated.',
                'error_code' => self::ERROR_PROMPT_MODIFIED,
                'expected' => $beforeText,
                'actual' => $this->findSimilarSection($currentPrompt, $beforeText),
                'can_force' => true,
            ];
        }

        return ['valid' => true];
    }

    /**
     * Apply a prompt suggestion to a flow
     *
     * @param  bool  $force  Force apply even if prompt was modified
     * @return array{success: bool, message: string, flow_id?: int, updated_at?: string, error_code?: string, expected?: string, actual?: string, can_force?: bool}
     */
    public function apply(QAWeeklyReport $report, int $suggestionIndex, Flow $flow, bool $force = false): array
    {
        // Validate first
        $validation = $this->validateSuggestion($report, $suggestionIndex, $flow);

        if (!$validation['valid']) {
            // If it's a conflict and force is true, we may proceed differently
            if ($validation['error_code'] === self::ERROR_PROMPT_MODIFIED && $force) {
                // Force apply will append the 'after' text at the end instead of replacing
                return $this->forceApply($report, $suggestionIndex, $flow);
            }

            // Return conflict response for prompt_modified errors
            if ($validation['error_code'] === self::ERROR_PROMPT_MODIFIED) {
                return [
                    'success' => false,
                    'conflict' => true,
                    'message' => $validation['error'],
                    'error_code' => $validation['error_code'],
                    'expected' => $validation['expected'] ?? null,
                    'actual' => $validation['actual'] ?? null,
                    'can_force' => $validation['can_force'] ?? false,
                ];
            }

            return [
                'success' => false,
                'message' => $validation['error'],
                'error_code' => $validation['error_code'] ?? 'validation_failed',
            ];
        }

        $suggestions = $report->prompt_suggestions;
        $suggestion = $suggestions[$suggestionIndex];

        return DB::transaction(function () use ($report, $suggestionIndex, $flow, $suggestions, $suggestion) {
            // Apply the replacement
            $currentPrompt = $flow->system_prompt ?? '';
            $newPrompt = str_replace($suggestion['before'], $suggestion['after'], $currentPrompt);

            // Update the flow
            $flow->update(['system_prompt' => $newPrompt]);

            // Mark suggestion as applied
            $suggestions[$suggestionIndex]['applied'] = true;
            $suggestions[$suggestionIndex]['applied_at'] = now()->toIso8601String();
            $suggestions[$suggestionIndex]['applied_to_flow_id'] = $flow->id;

            $report->update(['prompt_suggestions' => $suggestions]);

            Log::channel('qa_inspector')->info('Prompt suggestion applied', [
                'bot_id' => $report->bot_id,
                'report_id' => $report->id,
                'flow_id' => $flow->id,
                'suggestion_index' => $suggestionIndex,
                'issue_addressed' => $suggestion['issue_addressed'] ?? null,
                'section' => $suggestion['section'] ?? null,
            ]);

            return [
                'success' => true,
                'message' => 'Suggestion applied successfully',
                'flow_id' => $flow->id,
                'updated_at' => $flow->fresh()->updated_at->toIso8601String(),
            ];
        });
    }

    /**
     * Force apply a suggestion by appending the improvement as a note
     * Used when the original 'before' text cannot be found
     */
    protected function forceApply(QAWeeklyReport $report, int $suggestionIndex, Flow $flow): array
    {
        $suggestions = $report->prompt_suggestions;
        $suggestion = $suggestions[$suggestionIndex];

        return DB::transaction(function () use ($report, $suggestionIndex, $flow, $suggestions, $suggestion) {
            $currentPrompt = $flow->system_prompt ?? '';

            // Create a note explaining the forced addition
            $section = $suggestion['section'] ?? 'general';
            $issue = $suggestion['issue_addressed'] ?? 'quality improvement';

            $forcedAddition = "\n\n" .
                "# [QA Inspector Improvement - {$section}]\n" .
                "# Issue addressed: {$issue}\n" .
                $suggestion['after'];

            $newPrompt = $currentPrompt . $forcedAddition;

            // Update the flow
            $flow->update(['system_prompt' => $newPrompt]);

            // Mark suggestion as applied (forced)
            $suggestions[$suggestionIndex]['applied'] = true;
            $suggestions[$suggestionIndex]['applied_at'] = now()->toIso8601String();
            $suggestions[$suggestionIndex]['applied_to_flow_id'] = $flow->id;
            $suggestions[$suggestionIndex]['force_applied'] = true;

            $report->update(['prompt_suggestions' => $suggestions]);

            Log::channel('qa_inspector')->info('Prompt suggestion force-applied', [
                'bot_id' => $report->bot_id,
                'report_id' => $report->id,
                'flow_id' => $flow->id,
                'suggestion_index' => $suggestionIndex,
                'issue_addressed' => $issue,
                'section' => $section,
                'force_applied' => true,
            ]);

            return [
                'success' => true,
                'message' => 'Suggestion force-applied (appended to prompt)',
                'flow_id' => $flow->id,
                'updated_at' => $flow->fresh()->updated_at->toIso8601String(),
                'force_applied' => true,
            ];
        });
    }

    /**
     * Try to find a similar section in the current prompt
     * This helps users understand what changed
     */
    protected function findSimilarSection(string $currentPrompt, string $expectedText): ?string
    {
        // Take first 50 chars of expected text to search
        $searchStart = mb_substr($expectedText, 0, 50);

        // Try to find lines that start similarly
        $lines = explode("\n", $currentPrompt);
        $expectedLines = explode("\n", $expectedText);
        $firstExpectedLine = trim($expectedLines[0] ?? '');

        if (empty($firstExpectedLine)) {
            return null;
        }

        // Find lines that are similar to the first line of expected text
        foreach ($lines as $index => $line) {
            $trimmedLine = trim($line);
            // Check for similar start (at least 20 chars match)
            if (strlen($trimmedLine) >= 20 && strlen($firstExpectedLine) >= 20) {
                $similarity = similar_text(
                    mb_substr($trimmedLine, 0, 30),
                    mb_substr($firstExpectedLine, 0, 30)
                );
                if ($similarity > 15) {
                    // Return context around this line
                    $start = max(0, $index - 1);
                    $end = min(count($lines), $index + 5);
                    return implode("\n", array_slice($lines, $start, $end - $start));
                }
            }
        }

        return null;
    }

    /**
     * Preview what the prompt would look like after applying a suggestion
     */
    public function preview(QAWeeklyReport $report, int $suggestionIndex, Flow $flow): array
    {
        $validation = $this->validateSuggestion($report, $suggestionIndex, $flow);

        $suggestions = $report->prompt_suggestions;

        // Even if validation fails, we can still show a preview
        if ($suggestionIndex < 0 || $suggestionIndex >= count($suggestions ?? [])) {
            return [
                'success' => false,
                'message' => 'Invalid suggestion index',
            ];
        }

        $suggestion = $suggestions[$suggestionIndex];
        $currentPrompt = $flow->system_prompt ?? '';

        if ($validation['valid']) {
            // Normal preview - show the replacement
            $newPrompt = str_replace($suggestion['before'], $suggestion['after'], $currentPrompt);

            return [
                'success' => true,
                'current_prompt' => $currentPrompt,
                'new_prompt' => $newPrompt,
                'before' => $suggestion['before'],
                'after' => $suggestion['after'],
                'section' => $suggestion['section'] ?? null,
                'issue_addressed' => $suggestion['issue_addressed'] ?? null,
                'expected_impact' => $suggestion['expected_impact'] ?? null,
            ];
        }

        // Preview for force apply scenario
        if (($validation['error_code'] ?? null) === self::ERROR_PROMPT_MODIFIED) {
            $section = $suggestion['section'] ?? 'general';
            $issue = $suggestion['issue_addressed'] ?? 'quality improvement';

            $forcedAddition = "\n\n" .
                "# [QA Inspector Improvement - {$section}]\n" .
                "# Issue addressed: {$issue}\n" .
                $suggestion['after'];

            return [
                'success' => true,
                'conflict' => true,
                'current_prompt' => $currentPrompt,
                'new_prompt' => $currentPrompt . $forcedAddition,
                'before' => $suggestion['before'],
                'after' => $suggestion['after'],
                'section' => $suggestion['section'] ?? null,
                'issue_addressed' => $suggestion['issue_addressed'] ?? null,
                'expected_impact' => $suggestion['expected_impact'] ?? null,
                'force_preview' => true,
                'message' => 'Original text not found. Force apply will append the improvement.',
            ];
        }

        return [
            'success' => false,
            'message' => $validation['error'] ?? 'Cannot preview suggestion',
            'error_code' => $validation['error_code'] ?? 'unknown',
        ];
    }
}
