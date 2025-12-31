/**
 * Railway CLI wrapper for deployment operations
 */
import { type ShellResult } from "./shell.js";
import type { ServerConfig } from "../utils/config.js";
export interface RailwayService {
    name: string;
    status: string;
    url?: string;
}
/**
 * Get Railway deployment status
 */
export declare function getRailwayStatus(config: ServerConfig): Promise<ShellResult>;
/**
 * Get Railway logs for a service
 */
export declare function getRailwayLogs(service: "backend" | "frontend" | "reverb", lines: number, config: ServerConfig): Promise<ShellResult>;
/**
 * Deploy to Railway
 */
export declare function deployToRailway(service: "backend" | "frontend", config: ServerConfig): Promise<ShellResult>;
/**
 * Get Railway service URL
 */
export declare function getRailwayServiceUrl(service: string, config: ServerConfig): Promise<string | null>;
/**
 * Restart Railway service
 */
export declare function restartRailwayService(service: string, config: ServerConfig): Promise<ShellResult>;
/**
 * Check if Railway CLI is installed
 */
export declare function isRailwayInstalled(): Promise<boolean>;
/**
 * Check Railway connection/login status
 */
export declare function checkRailwayConnection(config: ServerConfig): Promise<{
    connected: boolean;
    project?: string;
}>;
//# sourceMappingURL=railway.d.ts.map