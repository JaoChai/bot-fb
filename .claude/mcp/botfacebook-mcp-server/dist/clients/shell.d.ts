/**
 * Shell command executor for local development
 */
import type { ServerConfig } from "../utils/config.js";
export interface ShellResult {
    success: boolean;
    stdout: string;
    stderr: string;
    exitCode: number;
    duration_ms: number;
}
export declare function executeShell(command: string, args: string[], config: ServerConfig, options?: {
    cwd?: string;
    timeout?: number;
    env?: Record<string, string>;
}): Promise<ShellResult>;
/**
 * Execute PHP artisan command
 */
export declare function artisan(command: string, config: ServerConfig, options?: {
    timeout?: number;
}): Promise<ShellResult>;
/**
 * Execute PHP code via Tinker
 */
export declare function tinker(code: string, config: ServerConfig): Promise<ShellResult>;
/**
 * Execute npm command in frontend directory
 */
export declare function npm(command: string, config: ServerConfig, options?: {
    timeout?: number;
}): Promise<ShellResult>;
/**
 * Read file content
 */
export declare function readFile(path: string, lines?: number): Promise<ShellResult>;
/**
 * Check if a process is running
 */
export declare function isProcessRunning(processName: string): Promise<boolean>;
//# sourceMappingURL=shell.d.ts.map