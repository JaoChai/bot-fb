/**
 * Diagnose Tool Implementation
 * System diagnostics for backend, frontend, database, queue, etc.
 */

import type { DiagnoseInput } from "../types/inputs.js";
import type { ServerConfig } from "../utils/config.js";
import { createSuccessResult, createErrorResult, type ToolResult, type HealthStatus } from "../types/common.js";
import { LaravelAPIClient } from "../clients/laravel-api.js";
import { artisan, readFile, isProcessRunning } from "../clients/shell.js";
import { getRailwayStatus, getRailwayLogs, checkRailwayConnection } from "../clients/railway.js";
import { isLocalMode } from "../utils/config.js";

interface DiagnoseResult {
  component: string;
  status: HealthStatus;
  details: Record<string, unknown>;
  recommendations?: string[];
}

export async function handleDiagnose(
  input: DiagnoseInput,
  config: ServerConfig
): Promise<ToolResult> {
  const { action, target, verbose, lines = 50 } = input;

  try {
    switch (action) {
      case "all":
        return await diagnoseAll(config, verbose);

      case "backend":
        return await diagnoseBackend(config, verbose);

      case "frontend":
        return await diagnoseFrontend(config, verbose);

      case "database":
        return await diagnoseDatabase(config, verbose);

      case "queue":
        return await diagnoseQueue(config, verbose);

      case "cache":
        return await diagnoseCache(config, verbose);

      case "api_keys":
        return await diagnoseApiKeys(config, verbose);

      case "routes":
        return await diagnoseRoutes(config, verbose);

      case "logs":
        return await diagnoseLogs(config, lines, target);

      case "railway":
        return await diagnoseRailway(config, verbose);

      default:
        return createErrorResult(`Unknown diagnose action: ${action}`);
    }
  } catch (error) {
    return createErrorResult(
      error instanceof Error ? error.message : String(error)
    );
  }
}

async function diagnoseAll(
  config: ServerConfig,
  verbose?: boolean
): Promise<ToolResult> {
  const results: DiagnoseResult[] = [];
  const errors: string[] = [];

  // Run diagnostics in parallel
  const diagnostics = await Promise.allSettled([
    diagnoseBackend(config, verbose),
    diagnoseDatabase(config, verbose),
    diagnoseQueue(config, verbose),
    diagnoseCache(config, verbose),
  ]);

  for (const result of diagnostics) {
    if (result.status === "fulfilled" && result.value.success) {
      results.push(result.value.data as DiagnoseResult);
    } else if (result.status === "rejected") {
      errors.push(result.reason?.message || "Unknown error");
    }
  }

  // Aggregate status
  const overallStatus: HealthStatus = results.some((r) => r.status === "error")
    ? "error"
    : results.some((r) => r.status === "degraded")
    ? "degraded"
    : "healthy";

  // Collect recommendations
  const recommendations = results
    .filter((r) => r.recommendations?.length)
    .flatMap((r) => r.recommendations!);

  return createSuccessResult({
    overall_status: overallStatus,
    components: results,
    recommendations: recommendations.length ? recommendations : undefined,
    errors: errors.length ? errors : undefined,
  });
}

async function diagnoseBackend(
  config: ServerConfig,
  verbose?: boolean
): Promise<ToolResult> {
  const recommendations: string[] = [];

  if (isLocalMode(config)) {
    // Local mode: use artisan
    const result = await artisan("--version", config);

    if (!result.success) {
      return createSuccessResult({
        component: "backend",
        status: "error",
        details: {
          error: result.stderr || "Laravel not accessible",
          mode: "local",
        },
        recommendations: ["Check if PHP is installed", "Verify LARAVEL_PATH is correct"],
      } as DiagnoseResult);
    }

    // Check health endpoint
    try {
      const client = new LaravelAPIClient(config);
      const health = await client.healthCheck();

      return createSuccessResult({
        component: "backend",
        status: "healthy",
        details: {
          laravel_version: result.stdout.trim(),
          health: health,
          mode: "local",
        },
      } as DiagnoseResult);
    } catch (error) {
      recommendations.push("Start Laravel server: php artisan serve");

      return createSuccessResult({
        component: "backend",
        status: "degraded",
        details: {
          laravel_version: result.stdout.trim(),
          api_error: error instanceof Error ? error.message : String(error),
          mode: "local",
        },
        recommendations,
      } as DiagnoseResult);
    }
  } else {
    // Remote mode: check production URL
    try {
      const client = new LaravelAPIClient(config);
      const health = await client.healthCheck();

      return createSuccessResult({
        component: "backend",
        status: "healthy",
        details: {
          health,
          mode: "remote",
          url: config.productionBackendUrl,
        },
      } as DiagnoseResult);
    } catch (error) {
      return createSuccessResult({
        component: "backend",
        status: "error",
        details: {
          error: error instanceof Error ? error.message : String(error),
          mode: "remote",
          url: config.productionBackendUrl,
        },
        recommendations: ["Check Railway deployment status", "Check Railway logs"],
      } as DiagnoseResult);
    }
  }
}

async function diagnoseFrontend(
  config: ServerConfig,
  verbose?: boolean
): Promise<ToolResult> {
  if (!isLocalMode(config)) {
    // Remote: just check if accessible
    try {
      const response = await fetch(config.productionFrontendUrl);
      return createSuccessResult({
        component: "frontend",
        status: response.ok ? "healthy" : "degraded",
        details: {
          http_status: response.status,
          url: config.productionFrontendUrl,
          mode: "remote",
        },
      } as DiagnoseResult);
    } catch (error) {
      return createSuccessResult({
        component: "frontend",
        status: "error",
        details: {
          error: error instanceof Error ? error.message : String(error),
          mode: "remote",
        },
      } as DiagnoseResult);
    }
  }

  // Local: check build status
  const result = await readFile(`${config.frontendPath}/dist/index.html`);

  if (result.success) {
    return createSuccessResult({
      component: "frontend",
      status: "healthy",
      details: {
        build_exists: true,
        mode: "local",
      },
    } as DiagnoseResult);
  }

  return createSuccessResult({
    component: "frontend",
    status: "degraded",
    details: {
      build_exists: false,
      mode: "local",
    },
    recommendations: ["Run: npm run build in frontend directory"],
  } as DiagnoseResult);
}

async function diagnoseDatabase(
  config: ServerConfig,
  verbose?: boolean
): Promise<ToolResult> {
  if (!isLocalMode(config)) {
    // Remote: use API to check
    try {
      const client = new LaravelAPIClient(config);
      const health = await client.healthCheck();

      return createSuccessResult({
        component: "database",
        status: "healthy",
        details: {
          connected: true,
          mode: "remote",
        },
      } as DiagnoseResult);
    } catch {
      return createSuccessResult({
        component: "database",
        status: "unknown",
        details: {
          mode: "remote",
          note: "Cannot check database directly in remote mode",
        },
      } as DiagnoseResult);
    }
  }

  // Local: use tinker
  const result = await artisan(
    "tinker --execute=\"echo DB::connection()->getPdo() ? 'connected' : 'failed'\"",
    config
  );

  if (result.success && result.stdout.includes("connected")) {
    return createSuccessResult({
      component: "database",
      status: "healthy",
      details: {
        connected: true,
        mode: "local",
      },
    } as DiagnoseResult);
  }

  return createSuccessResult({
    component: "database",
    status: "error",
    details: {
      connected: false,
      error: result.stderr || "Connection failed",
      mode: "local",
    },
    recommendations: [
      "Check DATABASE_URL in .env",
      "Verify PostgreSQL is running",
      "Check Neon dashboard for connection issues",
    ],
  } as DiagnoseResult);
}

async function diagnoseQueue(
  config: ServerConfig,
  verbose?: boolean
): Promise<ToolResult> {
  if (!isLocalMode(config)) {
    return createSuccessResult({
      component: "queue",
      status: "unknown",
      details: {
        mode: "remote",
        note: "Queue status check not available in remote mode",
      },
    } as DiagnoseResult);
  }

  // Check if queue worker is running
  const isRunning = await isProcessRunning("queue:work");

  // Check pending jobs
  const result = await artisan(
    "tinker --execute=\"echo DB::table('jobs')->count()\"",
    config
  );

  const pendingJobs = parseInt(result.stdout.trim()) || 0;

  const status: HealthStatus = !isRunning
    ? "degraded"
    : pendingJobs > 100
    ? "degraded"
    : "healthy";

  const recommendations: string[] = [];
  if (!isRunning) {
    recommendations.push("Start queue worker: php artisan queue:work");
  }
  if (pendingJobs > 100) {
    recommendations.push("High job backlog. Consider scaling workers.");
  }

  return createSuccessResult({
    component: "queue",
    status,
    details: {
      worker_running: isRunning,
      pending_jobs: pendingJobs,
      mode: "local",
    },
    recommendations: recommendations.length ? recommendations : undefined,
  } as DiagnoseResult);
}

async function diagnoseCache(
  config: ServerConfig,
  verbose?: boolean
): Promise<ToolResult> {
  if (!isLocalMode(config)) {
    return createSuccessResult({
      component: "cache",
      status: "unknown",
      details: {
        mode: "remote",
        note: "Cache status check not available in remote mode",
      },
    } as DiagnoseResult);
  }

  const result = await artisan(
    "tinker --execute=\"Cache::put('mcp_test', 'ok', 60); echo Cache::get('mcp_test')\"",
    config
  );

  if (result.success && result.stdout.includes("ok")) {
    return createSuccessResult({
      component: "cache",
      status: "healthy",
      details: {
        working: true,
        mode: "local",
      },
    } as DiagnoseResult);
  }

  return createSuccessResult({
    component: "cache",
    status: "degraded",
    details: {
      working: false,
      error: result.stderr,
      mode: "local",
    },
    recommendations: ["Check CACHE_DRIVER in .env", "Verify Redis is running if using redis driver"],
  } as DiagnoseResult);
}

async function diagnoseApiKeys(
  config: ServerConfig,
  verbose?: boolean
): Promise<ToolResult> {
  if (!isLocalMode(config)) {
    return createSuccessResult({
      component: "api_keys",
      status: "unknown",
      details: {
        mode: "remote",
        note: "API keys check not available in remote mode for security",
      },
    } as DiagnoseResult);
  }

  const result = await artisan(
    "tinker --execute=\"echo json_encode(['openrouter' => !empty(config('services.openrouter.api_key')), 'line' => !empty(config('services.line.channel_access_token'))])\"",
    config
  );

  try {
    const keys = JSON.parse(result.stdout.trim());
    const recommendations: string[] = [];

    if (!keys.openrouter) {
      recommendations.push("Set OPENROUTER_API_KEY in .env");
    }

    return createSuccessResult({
      component: "api_keys",
      status: recommendations.length ? "degraded" : "healthy",
      details: {
        openrouter_configured: keys.openrouter,
        line_configured: keys.line,
        mode: "local",
      },
      recommendations: recommendations.length ? recommendations : undefined,
    } as DiagnoseResult);
  } catch {
    return createSuccessResult({
      component: "api_keys",
      status: "error",
      details: {
        error: "Failed to check API keys",
        mode: "local",
      },
    } as DiagnoseResult);
  }
}

async function diagnoseRoutes(
  config: ServerConfig,
  verbose?: boolean
): Promise<ToolResult> {
  if (!isLocalMode(config)) {
    return createSuccessResult({
      component: "routes",
      status: "unknown",
      details: {
        mode: "remote",
        note: "Routes check not available in remote mode",
      },
    } as DiagnoseResult);
  }

  const result = await artisan("route:list --json", config);

  if (result.success) {
    try {
      const routes = JSON.parse(result.stdout);
      return createSuccessResult({
        component: "routes",
        status: "healthy",
        details: {
          total_routes: routes.length,
          routes: verbose ? routes : undefined,
          mode: "local",
        },
      } as DiagnoseResult);
    } catch {
      return createSuccessResult({
        component: "routes",
        status: "healthy",
        details: {
          output: result.stdout.substring(0, 1000),
          mode: "local",
        },
      } as DiagnoseResult);
    }
  }

  return createSuccessResult({
    component: "routes",
    status: "error",
    details: {
      error: result.stderr,
      mode: "local",
    },
  } as DiagnoseResult);
}

async function diagnoseLogs(
  config: ServerConfig,
  lines: number,
  target?: string
): Promise<ToolResult> {
  if (!isLocalMode(config)) {
    // Use Railway logs for remote
    const railwayResult = await getRailwayLogs(
      (target as "backend" | "frontend" | "reverb") || "backend",
      lines,
      config
    );

    return createSuccessResult({
      component: "logs",
      status: railwayResult.success ? "healthy" : "error",
      details: {
        source: "railway",
        service: target || "backend",
        logs: railwayResult.stdout || railwayResult.stderr,
        mode: "remote",
      },
    } as DiagnoseResult);
  }

  const logPath = `${config.laravelPath}/storage/logs/laravel.log`;
  const result = await readFile(logPath, lines);

  if (result.success) {
    // Check for errors in logs
    const errorCount = (result.stdout.match(/\[error\]/gi) || []).length;
    const warningCount = (result.stdout.match(/\[warning\]/gi) || []).length;

    return createSuccessResult({
      component: "logs",
      status: errorCount > 0 ? "degraded" : "healthy",
      details: {
        recent_errors: errorCount,
        recent_warnings: warningCount,
        logs: result.stdout,
        mode: "local",
      },
      recommendations: errorCount > 0 ? ["Check error logs for issues"] : undefined,
    } as DiagnoseResult);
  }

  return createSuccessResult({
    component: "logs",
    status: "error",
    details: {
      error: "Could not read log file",
      mode: "local",
    },
  } as DiagnoseResult);
}

async function diagnoseRailway(
  config: ServerConfig,
  verbose?: boolean
): Promise<ToolResult> {
  const connection = await checkRailwayConnection(config);

  if (!connection.connected) {
    return createSuccessResult({
      component: "railway",
      status: "error",
      details: {
        connected: false,
        error: "Not logged in to Railway",
      },
      recommendations: ["Run: railway login"],
    } as DiagnoseResult);
  }

  const status = await getRailwayStatus(config);

  return createSuccessResult({
    component: "railway",
    status: status.success ? "healthy" : "degraded",
    details: {
      connected: true,
      project: connection.project,
      status: status.stdout || status.stderr,
    },
  } as DiagnoseResult);
}
