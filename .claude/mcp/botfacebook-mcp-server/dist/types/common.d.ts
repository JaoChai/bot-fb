/**
 * Common types for BotFacebook MCP Server
 */
export type HealthStatus = "healthy" | "degraded" | "error" | "unknown";
export type DangerLevel = "safe" | "moderate" | "dangerous";
export interface ToolResult {
    success: boolean;
    message?: string;
    data?: unknown;
    error?: string;
    warnings?: string[];
    timestamp: string;
}
export interface PaginationMeta {
    total: number;
    page: number;
    per_page: number;
    last_page: number;
}
export interface ApiError {
    status: number;
    message: string;
    errors?: Record<string, string[]>;
}
export declare function createSuccessResult(data: unknown, message?: string): ToolResult;
export declare function createErrorResult(error: string, data?: unknown): ToolResult;
//# sourceMappingURL=common.d.ts.map