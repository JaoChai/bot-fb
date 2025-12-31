/**
 * Railway CLI wrapper for deployment operations
 */
import { executeShell } from "./shell.js";
/**
 * Get Railway deployment status
 */
export async function getRailwayStatus(config) {
    return executeShell("railway", ["status"], config, { timeout: 30000 });
}
/**
 * Get Railway logs for a service
 */
export async function getRailwayLogs(service, lines, config) {
    return executeShell("railway", ["logs", "--service", service, "-n", lines.toString()], config, { timeout: 60000 });
}
/**
 * Deploy to Railway
 */
export async function deployToRailway(service, config) {
    const cwd = service === "backend" ? config.laravelPath : config.frontendPath;
    return executeShell("railway", ["up", "--service", service, "--detach"], config, { cwd, timeout: 300000 } // 5 minutes for deployment
    );
}
/**
 * Get Railway service URL
 */
export async function getRailwayServiceUrl(service, config) {
    const result = await executeShell("railway", ["domain", "--service", service], config);
    if (result.success && result.stdout.trim()) {
        return result.stdout.trim();
    }
    return null;
}
/**
 * Restart Railway service
 */
export async function restartRailwayService(service, config) {
    return executeShell("railway", ["redeploy", "--service", service], config, { timeout: 300000 });
}
/**
 * Check if Railway CLI is installed
 */
export async function isRailwayInstalled() {
    const result = await executeShell("which", ["railway"], {});
    return result.success;
}
/**
 * Check Railway connection/login status
 */
export async function checkRailwayConnection(config) {
    const result = await executeShell("railway", ["whoami"], config);
    if (result.success) {
        return {
            connected: true,
            project: config.railwayProject,
        };
    }
    return { connected: false };
}
//# sourceMappingURL=railway.js.map