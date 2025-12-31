/**
 * Railway CLI wrapper for deployment operations
 */

import { executeShell, type ShellResult } from "./shell.js";
import type { ServerConfig } from "../utils/config.js";

export interface RailwayService {
  name: string;
  status: string;
  url?: string;
}

/**
 * Get Railway deployment status
 */
export async function getRailwayStatus(
  config: ServerConfig
): Promise<ShellResult> {
  return executeShell(
    "railway",
    ["status"],
    config,
    { timeout: 30000 }
  );
}

/**
 * Get Railway logs for a service
 */
export async function getRailwayLogs(
  service: "backend" | "frontend" | "reverb",
  lines: number,
  config: ServerConfig
): Promise<ShellResult> {
  return executeShell(
    "railway",
    ["logs", "--service", service, "-n", lines.toString()],
    config,
    { timeout: 60000 }
  );
}

/**
 * Deploy to Railway
 */
export async function deployToRailway(
  service: "backend" | "frontend",
  config: ServerConfig
): Promise<ShellResult> {
  const cwd = service === "backend" ? config.laravelPath : config.frontendPath;
  return executeShell(
    "railway",
    ["up", "--service", service, "--detach"],
    config,
    { cwd, timeout: 300000 } // 5 minutes for deployment
  );
}

/**
 * Get Railway service URL
 */
export async function getRailwayServiceUrl(
  service: string,
  config: ServerConfig
): Promise<string | null> {
  const result = await executeShell(
    "railway",
    ["domain", "--service", service],
    config
  );

  if (result.success && result.stdout.trim()) {
    return result.stdout.trim();
  }
  return null;
}

/**
 * Restart Railway service
 */
export async function restartRailwayService(
  service: string,
  config: ServerConfig
): Promise<ShellResult> {
  return executeShell(
    "railway",
    ["redeploy", "--service", service],
    config,
    { timeout: 300000 }
  );
}

/**
 * Check if Railway CLI is installed
 */
export async function isRailwayInstalled(): Promise<boolean> {
  const result = await executeShell(
    "which",
    ["railway"],
    {} as ServerConfig
  );
  return result.success;
}

/**
 * Check Railway connection/login status
 */
export async function checkRailwayConnection(
  config: ServerConfig
): Promise<{ connected: boolean; project?: string }> {
  const result = await executeShell(
    "railway",
    ["whoami"],
    config
  );

  if (result.success) {
    return {
      connected: true,
      project: config.railwayProject,
    };
  }

  return { connected: false };
}

/**
 * List Railway services in the project
 */
export async function listRailwayServices(
  config: ServerConfig
): Promise<ShellResult> {
  return executeShell(
    "railway",
    ["service", "status"],
    config,
    { timeout: 30000 }
  );
}

/**
 * Get Railway environment variables
 */
export async function getRailwayVariables(
  service: string | undefined,
  config: ServerConfig
): Promise<ShellResult> {
  const args = ["variables", "--json"];
  if (service) {
    args.push("--service", service);
  }
  return executeShell("railway", args, config, { timeout: 30000 });
}

/**
 * Set Railway environment variable
 */
export async function setRailwayVariable(
  name: string,
  value: string,
  service: string | undefined,
  config: ServerConfig
): Promise<ShellResult> {
  const args = ["variables", "set", `${name}=${value}`];
  if (service) {
    args.push("--service", service);
  }
  return executeShell("railway", args, config, { timeout: 30000 });
}
