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

export function createSuccessResult(data: unknown, message?: string): ToolResult {
  return {
    success: true,
    message,
    data,
    timestamp: new Date().toISOString(),
  };
}

export function createErrorResult(error: string, data?: unknown): ToolResult {
  return {
    success: false,
    error,
    data,
    timestamp: new Date().toISOString(),
  };
}
