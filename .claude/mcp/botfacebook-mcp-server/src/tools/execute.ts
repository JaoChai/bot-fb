/**
 * Execute Tool Implementation
 * General actions: cost, security, deploy, test, tinker
 */

import type { ExecuteInput } from "../types/inputs.js";
import type { ServerConfig } from "../utils/config.js";
import { createSuccessResult, createErrorResult, type ToolResult } from "../types/common.js";
import { LaravelAPIClient } from "../clients/laravel-api.js";
import { tinker, artisan, npm } from "../clients/shell.js";
import {
  getRailwayLogs,
  getRailwayStatus,
  deployToRailway,
} from "../clients/railway.js";
import { isLocalMode } from "../utils/config.js";
import {
  validateConfirmation,
  checkRateLimit,
} from "../safety/confirmation.js";
import {
  validateTinkerCode,
  validateBotId,
  validateDate,
  validateRailwayService,
} from "../safety/validators.js";

export async function handleExecute(
  input: ExecuteInput,
  config: ServerConfig
): Promise<ToolResult> {
  const {
    action,
    bot_id,
    token_id,
    from_date,
    to_date,
    group_by,
    code,
    service,
    lines = 100,
    confirm,
  } = input;

  try {
    // Rate limiting
    if (action === "tinker") {
      checkRateLimit("execute", action);
    }

    // Confirmation for dangerous actions
    if (["deploy_backend", "deploy_frontend", "revoke_token"].includes(action)) {
      validateConfirmation("execute", action, confirm);
    }

    switch (action) {
      // ============================================
      // COST/ANALYTICS ACTIONS
      // ============================================
      case "cost_summary": {
        const client = new LaravelAPIClient(config);
        const response = await client.getCostAnalytics({
          from_date: validateDate(from_date),
          to_date: validateDate(to_date),
          group_by,
        });
        return createSuccessResult(response, "Cost summary retrieved");
      }

      case "cost_by_bot": {
        const client = new LaravelAPIClient(config);
        const response = await client.getCostAnalytics({
          from_date: validateDate(from_date),
          to_date: validateDate(to_date),
          group_by: "bot",
        });
        return createSuccessResult(response, "Cost by bot retrieved");
      }

      case "cost_by_model": {
        const client = new LaravelAPIClient(config);
        const response = await client.getCostAnalytics({
          from_date: validateDate(from_date),
          to_date: validateDate(to_date),
          group_by: "model",
        });
        return createSuccessResult(response, "Cost by model retrieved");
      }

      // ============================================
      // SECURITY ACTIONS
      // ============================================
      case "check_api_keys": {
        if (!isLocalMode(config)) {
          return createErrorResult("API keys check not available in remote mode for security");
        }

        const result = await tinker(`
          echo json_encode([
            'openrouter' => !empty(config('services.openrouter.api_key')),
            'line' => !empty(config('services.line.channel_access_token')),
          ]);
        `, config);

        try {
          const keys = JSON.parse(result.stdout.trim());
          return createSuccessResult({
            openrouter_configured: keys.openrouter,
            line_configured: keys.line,
          }, "API keys checked");
        } catch {
          return createErrorResult("Failed to check API keys");
        }
      }

      case "rotate_webhook": {
        const botId = validateBotId(bot_id, true);
        const client = new LaravelAPIClient(config);
        const response = await client.post(`/api/bots/${botId}/regenerate-webhook`);
        return createSuccessResult(response, "Webhook URL regenerated");
      }

      case "list_tokens": {
        const client = new LaravelAPIClient(config);
        const response = await client.listTokens();
        return createSuccessResult(response, "Auth tokens listed");
      }

      case "revoke_token": {
        if (!token_id) {
          return createErrorResult("token_id is required");
        }
        const client = new LaravelAPIClient(config);
        const response = await client.revokeToken(token_id);
        return createSuccessResult(response, "Token revoked");
      }

      // ============================================
      // DEPLOY ACTIONS
      // ============================================
      case "deploy_backend": {
        const result = await deployToRailway("backend", config);
        return result.success
          ? createSuccessResult({
              output: result.stdout,
              duration_ms: result.duration_ms,
            }, "Backend deployment started")
          : createErrorResult(`Deployment failed: ${result.stderr}`);
      }

      case "deploy_frontend": {
        const result = await deployToRailway("frontend", config);
        return result.success
          ? createSuccessResult({
              output: result.stdout,
              duration_ms: result.duration_ms,
            }, "Frontend deployment started")
          : createErrorResult(`Deployment failed: ${result.stderr}`);
      }

      case "railway_logs": {
        const svc = validateRailwayService(service);
        const result = await getRailwayLogs(svc, lines, config);
        return createSuccessResult({
          service: svc,
          logs: result.stdout || result.stderr,
        }, `Railway logs for ${svc}`);
      }

      case "railway_status": {
        const result = await getRailwayStatus(config);
        return createSuccessResult({
          output: result.stdout || result.stderr,
        }, "Railway status retrieved");
      }

      // ============================================
      // TEST ACTIONS
      // ============================================
      case "run_e2e": {
        if (!isLocalMode(config)) {
          return createErrorResult("E2E tests can only run locally");
        }
        // Placeholder - actual implementation depends on test setup
        return createSuccessResult({
          note: "E2E test execution placeholder",
          command: "npm run test:e2e",
        }, "E2E tests would run here");
      }

      case "run_unit": {
        if (!isLocalMode(config)) {
          return createErrorResult("Unit tests can only run locally");
        }

        const result = await artisan("test", config, { timeout: 300000 });
        return result.success
          ? createSuccessResult({
              output: result.stdout,
              duration_ms: result.duration_ms,
            }, "Unit tests completed")
          : createSuccessResult({
              output: result.stdout,
              errors: result.stderr,
              duration_ms: result.duration_ms,
            }, "Unit tests completed with failures");
      }

      case "test_webhook": {
        const botId = validateBotId(bot_id, true);
        const client = new LaravelAPIClient(config);
        const response = await client.post(`/api/bots/${botId}/test-line`);
        return createSuccessResult(response, "Webhook test completed");
      }

      // ============================================
      // TINKER
      // ============================================
      case "tinker": {
        if (!isLocalMode(config)) {
          return createErrorResult("Tinker only available in local mode for security");
        }

        if (!code) {
          return createErrorResult("code is required for tinker action");
        }

        // Validate code for dangerous patterns
        validateTinkerCode(code);

        const result = await tinker(code, config);

        return result.success
          ? createSuccessResult({
              output: result.stdout,
              duration_ms: result.duration_ms,
            }, "Tinker command executed")
          : createErrorResult(`Tinker failed: ${result.stderr}`);
      }

      default:
        return createErrorResult(`Unknown execute action: ${action}`);
    }
  } catch (error) {
    return createErrorResult(
      error instanceof Error ? error.message : String(error)
    );
  }
}
