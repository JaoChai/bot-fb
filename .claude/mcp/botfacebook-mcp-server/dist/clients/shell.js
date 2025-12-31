/**
 * Shell command executor for local development
 */
import { spawn } from "child_process";
export async function executeShell(command, args, config, options) {
    const startTime = Date.now();
    const timeout = options?.timeout || 60000; // 60s default
    return new Promise((resolve) => {
        const proc = spawn(command, args, {
            cwd: options?.cwd || process.cwd(),
            env: { ...process.env, ...options?.env },
            shell: true,
        });
        let stdout = "";
        let stderr = "";
        proc.stdout.on("data", (data) => {
            stdout += data.toString();
        });
        proc.stderr.on("data", (data) => {
            stderr += data.toString();
        });
        const timeoutId = setTimeout(() => {
            proc.kill("SIGTERM");
            resolve({
                success: false,
                stdout,
                stderr: stderr + "\nProcess killed due to timeout",
                exitCode: -1,
                duration_ms: Date.now() - startTime,
            });
        }, timeout);
        proc.on("close", (exitCode) => {
            clearTimeout(timeoutId);
            resolve({
                success: exitCode === 0,
                stdout,
                stderr,
                exitCode: exitCode ?? -1,
                duration_ms: Date.now() - startTime,
            });
        });
        proc.on("error", (error) => {
            clearTimeout(timeoutId);
            resolve({
                success: false,
                stdout,
                stderr: error.message,
                exitCode: -1,
                duration_ms: Date.now() - startTime,
            });
        });
    });
}
/**
 * Execute PHP artisan command
 */
export async function artisan(command, config, options) {
    return executeShell(config.phpPath, ["artisan", command], config, {
        cwd: config.laravelPath,
        timeout: options?.timeout,
    });
}
/**
 * Execute PHP code via Tinker
 */
export async function tinker(code, config) {
    // Escape the code for shell execution
    const escapedCode = code.replace(/'/g, "'\\''");
    return executeShell(config.phpPath, ["artisan", "tinker", "--execute", `'${escapedCode}'`], config, {
        cwd: config.laravelPath,
        timeout: 30000, // 30s timeout for tinker
    });
}
/**
 * Execute npm command in frontend directory
 */
export async function npm(command, config, options) {
    return executeShell("npm", ["run", command], config, {
        cwd: config.frontendPath,
        timeout: options?.timeout || 300000, // 5 min default for builds
    });
}
/**
 * Read file content
 */
export async function readFile(path, lines) {
    const args = lines ? ["-n", lines.toString(), path] : [path];
    return executeShell("tail", args, {});
}
/**
 * Check if a process is running
 */
export async function isProcessRunning(processName) {
    const result = await executeShell("pgrep", ["-f", processName], {});
    return result.success;
}
//# sourceMappingURL=shell.js.map