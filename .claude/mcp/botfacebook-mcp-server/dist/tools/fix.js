/**
 * Fix Tool Implementation
 * System repairs and maintenance operations
 */
import { createSuccessResult, createErrorResult } from "../types/common.js";
import { artisan, npm, tinker } from "../clients/shell.js";
import { isLocalMode } from "../utils/config.js";
import { validateConfirmation, checkRateLimit, getDangerWarning, } from "../safety/confirmation.js";
import { validateBotId } from "../safety/validators.js";
export async function handleFix(input, config) {
    const { action, target, confirm, force } = input;
    try {
        // Check rate limits
        checkRateLimit("fix", action);
        // Validate confirmation for dangerous actions
        if (!force) {
            validateConfirmation("fix", action, confirm);
        }
        // Get warning if applicable
        const warning = getDangerWarning("fix", action);
        // Execute the fix
        let result;
        switch (action) {
            case "clear_cache":
                result = await clearCache(config);
                break;
            case "clear_routes":
                result = await clearRoutes(config);
                break;
            case "clear_views":
                result = await clearViews(config);
                break;
            case "clear_config":
                result = await clearConfig(config);
                break;
            case "clear_all":
                result = await clearAll(config);
                break;
            case "optimize":
                result = await optimize(config);
                break;
            case "restart_queue":
                result = await restartQueue(config);
                break;
            case "migrate":
                result = await migrate(config);
                break;
            case "migrate_fresh":
                result = await migrateFresh(config);
                break;
            case "seed":
                result = await seed(config);
                break;
            case "rebuild_frontend":
                result = await rebuildFrontend(config);
                break;
            case "reindex_kb":
                const botId = validateBotId(target, true);
                result = await reindexKnowledgeBase(config, botId);
                break;
            default:
                return createErrorResult(`Unknown fix action: ${action}`);
        }
        // Add warning to result if applicable
        if (warning && result.success) {
            return {
                ...result,
                warnings: [warning, ...(result.warnings || [])],
            };
        }
        return result;
    }
    catch (error) {
        return createErrorResult(error instanceof Error ? error.message : String(error));
    }
}
async function clearCache(config) {
    if (!isLocalMode(config)) {
        return createErrorResult("Cache clear not available in remote mode. Use Railway redeploy.");
    }
    const result = await artisan("cache:clear", config);
    return result.success
        ? createSuccessResult({
            action: "clear_cache",
            output: result.stdout,
            duration_ms: result.duration_ms,
        }, "Cache cleared successfully")
        : createErrorResult(result.stderr || "Failed to clear cache");
}
async function clearRoutes(config) {
    if (!isLocalMode(config)) {
        return createErrorResult("Route cache clear not available in remote mode");
    }
    const result = await artisan("route:clear", config);
    return result.success
        ? createSuccessResult({
            action: "clear_routes",
            output: result.stdout,
            duration_ms: result.duration_ms,
        }, "Route cache cleared")
        : createErrorResult(result.stderr || "Failed to clear route cache");
}
async function clearViews(config) {
    if (!isLocalMode(config)) {
        return createErrorResult("View cache clear not available in remote mode");
    }
    const result = await artisan("view:clear", config);
    return result.success
        ? createSuccessResult({
            action: "clear_views",
            output: result.stdout,
            duration_ms: result.duration_ms,
        }, "View cache cleared")
        : createErrorResult(result.stderr || "Failed to clear view cache");
}
async function clearConfig(config) {
    if (!isLocalMode(config)) {
        return createErrorResult("Config cache clear not available in remote mode");
    }
    const result = await artisan("config:clear", config);
    return result.success
        ? createSuccessResult({
            action: "clear_config",
            output: result.stdout,
            duration_ms: result.duration_ms,
        }, "Config cache cleared")
        : createErrorResult(result.stderr || "Failed to clear config cache");
}
async function clearAll(config) {
    if (!isLocalMode(config)) {
        return createErrorResult("Cache clear not available in remote mode");
    }
    const results = [];
    // Clear all caches
    const commands = [
        { name: "cache:clear", label: "Application cache" },
        { name: "route:clear", label: "Route cache" },
        { name: "view:clear", label: "View cache" },
        { name: "config:clear", label: "Config cache" },
    ];
    for (const cmd of commands) {
        const result = await artisan(cmd.name, config);
        results.push({
            command: cmd.label,
            success: result.success,
            output: result.success ? "Cleared" : result.stderr,
        });
    }
    const allSuccess = results.every((r) => r.success);
    return allSuccess
        ? createSuccessResult({
            action: "clear_all",
            results,
        }, "All caches cleared successfully")
        : createSuccessResult({
            action: "clear_all",
            results,
        }, "Some caches failed to clear");
}
async function optimize(config) {
    if (!isLocalMode(config)) {
        return createErrorResult("Optimize not available in remote mode");
    }
    const result = await artisan("optimize", config);
    return result.success
        ? createSuccessResult({
            action: "optimize",
            output: result.stdout,
            duration_ms: result.duration_ms,
        }, "Laravel optimized")
        : createErrorResult(result.stderr || "Failed to optimize");
}
async function restartQueue(config) {
    if (!isLocalMode(config)) {
        return createErrorResult("Queue restart not available in remote mode. Use Railway redeploy.");
    }
    const result = await artisan("queue:restart", config);
    return result.success
        ? createSuccessResult({
            action: "restart_queue",
            output: result.stdout,
            duration_ms: result.duration_ms,
        }, "Queue workers signaled to restart")
        : createErrorResult(result.stderr || "Failed to restart queue");
}
async function migrate(config) {
    if (!isLocalMode(config)) {
        return createErrorResult("Migration not available in remote mode. Use Railway deployment.");
    }
    const result = await artisan("migrate --force", config, { timeout: 120000 });
    return result.success
        ? createSuccessResult({
            action: "migrate",
            output: result.stdout,
            duration_ms: result.duration_ms,
        }, "Migrations completed")
        : createErrorResult(result.stderr || "Migration failed");
}
async function migrateFresh(config) {
    if (!isLocalMode(config)) {
        return createErrorResult("Fresh migration not available in remote mode for safety");
    }
    const result = await artisan("migrate:fresh --force", config, { timeout: 180000 });
    return result.success
        ? createSuccessResult({
            action: "migrate_fresh",
            output: result.stdout,
            duration_ms: result.duration_ms,
        }, "Fresh migration completed - all tables recreated")
        : createErrorResult(result.stderr || "Fresh migration failed");
}
async function seed(config) {
    if (!isLocalMode(config)) {
        return createErrorResult("Seeding not available in remote mode for safety");
    }
    const result = await artisan("db:seed --force", config, { timeout: 120000 });
    return result.success
        ? createSuccessResult({
            action: "seed",
            output: result.stdout,
            duration_ms: result.duration_ms,
        }, "Database seeded")
        : createErrorResult(result.stderr || "Seeding failed");
}
async function rebuildFrontend(config) {
    if (!isLocalMode(config)) {
        return createErrorResult("Frontend rebuild not available in remote mode. Use Railway deployment.");
    }
    // First install dependencies
    const installResult = await npm("install", config, { timeout: 180000 });
    if (!installResult.success) {
        return createErrorResult(`npm install failed: ${installResult.stderr}`);
    }
    // Then build
    const buildResult = await npm("build", config, { timeout: 300000 });
    return buildResult.success
        ? createSuccessResult({
            action: "rebuild_frontend",
            install_output: installResult.stdout.substring(0, 500),
            build_output: buildResult.stdout.substring(0, 500),
            duration_ms: installResult.duration_ms + buildResult.duration_ms,
        }, "Frontend rebuilt successfully")
        : createErrorResult(`Build failed: ${buildResult.stderr}`);
}
async function reindexKnowledgeBase(config, botId) {
    if (!isLocalMode(config)) {
        return createErrorResult("KB reindex not available in remote mode");
    }
    // Use tinker to trigger reindexing
    const code = `
    $kb = \\App\\Models\\KnowledgeBase::whereHas('bot', fn($q) => $q->where('id', ${botId}))->first();
    if (!$kb) {
      echo json_encode(['error' => 'Knowledge base not found']);
      return;
    }
    $documents = $kb->documents;
    $count = 0;
    foreach ($documents as $doc) {
      dispatch(new \\App\\Jobs\\ProcessDocumentJob($doc->id));
      $count++;
    }
    echo json_encode(['success' => true, 'documents_queued' => $count]);
  `;
    const result = await tinker(code, config);
    try {
        const output = JSON.parse(result.stdout.trim());
        if (output.error) {
            return createErrorResult(output.error);
        }
        return createSuccessResult({
            action: "reindex_kb",
            bot_id: botId,
            documents_queued: output.documents_queued,
        }, `Queued ${output.documents_queued} documents for reindexing`);
    }
    catch {
        return createErrorResult(`Reindex failed: ${result.stderr || result.stdout}`);
    }
}
//# sourceMappingURL=fix.js.map