/**
 * Evaluate Tool Implementation
 * Bot evaluation and quality assessment
 */

import type { EvaluateInput } from "../types/inputs.js";
import type { ServerConfig } from "../utils/config.js";
import { createSuccessResult, createErrorResult, type ToolResult } from "../types/common.js";
import { LaravelAPIClient } from "../clients/laravel-api.js";
import { validateBotId, validateTestCount, validatePagination } from "../safety/validators.js";

export async function handleEvaluate(
  input: EvaluateInput,
  config: ServerConfig
): Promise<ToolResult> {
  const {
    action,
    bot_id,
    evaluation_id,
    test_case_id,
    config: evalConfig,
    evaluation_ids,
    page,
    per_page,
    status,
  } = input;

  try {
    const botId = validateBotId(bot_id, true);
    const client = new LaravelAPIClient(config);

    switch (action) {
      // ============================================
      // LIST EVALUATIONS
      // ============================================
      case "list": {
        const pagination = validatePagination(page, per_page);
        const response = await client.listEvaluations(
          botId!,
          pagination.page,
          status
        );
        return createSuccessResult(response, "Evaluations retrieved");
      }

      // ============================================
      // CREATE EVALUATION
      // ============================================
      case "create": {
        if (!evalConfig) {
          return createErrorResult("config is required for create action");
        }
        if (!evalConfig.flow_id) {
          return createErrorResult("config.flow_id is required");
        }

        const testCount = validateTestCount(evalConfig.test_count);

        const requestConfig = {
          ...evalConfig,
          test_count: testCount,
        };

        const response = await client.createEvaluation(botId!, requestConfig);
        return createSuccessResult(response, "Evaluation created and started");
      }

      // ============================================
      // SHOW EVALUATION
      // ============================================
      case "show": {
        if (!evaluation_id) {
          return createErrorResult("evaluation_id is required");
        }
        const response = await client.getEvaluation(botId!, evaluation_id);
        return createSuccessResult(response, "Evaluation details retrieved");
      }

      // ============================================
      // GET PROGRESS
      // ============================================
      case "progress": {
        if (!evaluation_id) {
          return createErrorResult("evaluation_id is required");
        }
        const response = await client.getEvaluationProgress(botId!, evaluation_id);
        return createSuccessResult(response, "Evaluation progress retrieved");
      }

      // ============================================
      // GET TEST CASES
      // ============================================
      case "test_cases": {
        if (!evaluation_id) {
          return createErrorResult("evaluation_id is required");
        }
        const response = await client.getEvaluationTestCases(botId!, evaluation_id);
        return createSuccessResult(response, "Test cases retrieved");
      }

      // ============================================
      // GET TEST CASE DETAIL
      // ============================================
      case "test_case_detail": {
        if (!evaluation_id) {
          return createErrorResult("evaluation_id is required");
        }
        if (!test_case_id) {
          return createErrorResult("test_case_id is required");
        }
        const response = await client.get(
          `/api/bots/${botId}/evaluations/${evaluation_id}/test-cases/${test_case_id}`
        );
        return createSuccessResult(response, "Test case detail retrieved");
      }

      // ============================================
      // GET REPORT
      // ============================================
      case "report": {
        if (!evaluation_id) {
          return createErrorResult("evaluation_id is required");
        }
        const response = await client.getEvaluationReport(botId!, evaluation_id);
        return createSuccessResult(response, "Evaluation report retrieved");
      }

      // ============================================
      // CANCEL EVALUATION
      // ============================================
      case "cancel": {
        if (!evaluation_id) {
          return createErrorResult("evaluation_id is required");
        }
        const response = await client.cancelEvaluation(botId!, evaluation_id);
        return createSuccessResult(response, "Evaluation cancelled");
      }

      // ============================================
      // RETRY EVALUATION
      // ============================================
      case "retry": {
        if (!evaluation_id) {
          return createErrorResult("evaluation_id is required");
        }
        const response = await client.retryEvaluation(botId!, evaluation_id);
        return createSuccessResult(response, "Evaluation retry started");
      }

      // ============================================
      // COMPARE EVALUATIONS
      // ============================================
      case "compare": {
        if (!evaluation_ids || evaluation_ids.length < 2) {
          return createErrorResult("evaluation_ids with at least 2 IDs is required for compare");
        }

        // Fetch all evaluations
        const evaluations = await Promise.all(
          evaluation_ids.map((id) => client.getEvaluation(botId!, id))
        );

        // Build comparison
        const comparison = evaluations.map((eval_result, index) => {
          const data = (eval_result as { data?: Record<string, unknown> }).data || {};
          return {
            evaluation_id: evaluation_ids[index],
            overall_score: data.overall_score as number | undefined,
            metric_scores: data.metric_scores,
            completed_at: data.completed_at,
          };
        });

        // Calculate improvement
        if (comparison.length === 2) {
          const score1 = comparison[0].overall_score;
          const score2 = comparison[1].overall_score;
          if (score1 && score2) {
            const improvement = ((score2 - score1) / score1 * 100).toFixed(2);
            return createSuccessResult({
              evaluations: comparison,
              improvement: `${improvement}%`,
              improved: score2 > score1,
            }, `Comparison complete. ${score2 > score1 ? "Improved" : "Declined"} by ${Math.abs(parseFloat(improvement))}%`);
          }
        }

        return createSuccessResult({
          evaluations: comparison,
        }, "Evaluations compared");
      }

      // ============================================
      // GET PERSONAS
      // ============================================
      case "personas": {
        const response = await client.getEvaluationPersonas();
        return createSuccessResult(response, "Evaluation personas retrieved");
      }

      default:
        return createErrorResult(`Unknown evaluate action: ${action}`);
    }
  } catch (error) {
    return createErrorResult(
      error instanceof Error ? error.message : String(error)
    );
  }
}
