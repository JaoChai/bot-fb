/**
 * Input validators and sanitizers
 */

import { z } from "zod";
import { SafetyError } from "./confirmation.js";

/**
 * Validate and sanitize tinker code
 */
export function validateTinkerCode(code: string): void {
  // Block dangerous SQL operations
  const dangerousPatterns = [
    /DB::statement\s*\(/i,
    /Schema::drop/i,
    /\bDROP\s+TABLE\b/i,
    /\bDROP\s+DATABASE\b/i,
    /\bTRUNCATE\s+TABLE\b/i,
    /\bDELETE\s+FROM\b/i,
    /DB::unprepared/i,
    /\bexec\s*\(/i,
    /\bshell_exec\s*\(/i,
    /\bsystem\s*\(/i,
    /\bpassthru\s*\(/i,
    /\beval\s*\(/i,
    /file_put_contents/i,
    /unlink\s*\(/i,
    /rmdir\s*\(/i,
  ];

  for (const pattern of dangerousPatterns) {
    if (pattern.test(code)) {
      throw new SafetyError(
        "Dangerous code pattern detected. This operation is not allowed.",
        "DANGEROUS_CODE"
      );
    }
  }
}

/**
 * Validate bot ID
 */
export function validateBotId(botId: unknown, required = false): number | undefined {
  if (botId === undefined || botId === null) {
    if (required) {
      throw new SafetyError("bot_id is required", "VALIDATION_ERROR");
    }
    return undefined;
  }

  const id = Number(botId);
  if (isNaN(id) || id <= 0) {
    throw new SafetyError("bot_id must be a positive integer", "VALIDATION_ERROR");
  }

  return id;
}

/**
 * Validate pagination params
 */
export function validatePagination(page?: unknown, limit?: unknown): { page: number; limit: number } {
  const pageNum = page ? Number(page) : 1;
  const limitNum = limit ? Number(limit) : 20;

  if (isNaN(pageNum) || pageNum < 1) {
    throw new SafetyError("page must be a positive integer", "VALIDATION_ERROR");
  }

  if (isNaN(limitNum) || limitNum < 1 || limitNum > 100) {
    throw new SafetyError("limit must be between 1 and 100", "VALIDATION_ERROR");
  }

  return { page: pageNum, limit: limitNum };
}

/**
 * Validate date string
 */
export function validateDate(date: string | undefined): string | undefined {
  if (!date) return undefined;

  const dateSchema = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, "Date must be in YYYY-MM-DD format");

  try {
    return dateSchema.parse(date);
  } catch {
    throw new SafetyError("Invalid date format. Use YYYY-MM-DD", "VALIDATION_ERROR");
  }
}

/**
 * Validate test count for evaluation
 */
export function validateTestCount(count: number | undefined): number {
  if (count === undefined) return 20; // default

  if (count < 10 || count > 100) {
    throw new SafetyError("test_count must be between 10 and 100", "VALIDATION_ERROR");
  }

  return count;
}

/**
 * Sanitize string input
 */
export function sanitizeString(input: string | undefined): string | undefined {
  if (!input) return undefined;

  // Remove potential script injection
  return input
    .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, "")
    .replace(/javascript:/gi, "")
    .trim();
}

/**
 * Validate message content
 */
export function validateMessage(message: string | undefined, required = false): string | undefined {
  if (!message || message.trim() === "") {
    if (required) {
      throw new SafetyError("message is required", "VALIDATION_ERROR");
    }
    return undefined;
  }

  if (message.length > 10000) {
    throw new SafetyError("message must be less than 10000 characters", "VALIDATION_ERROR");
  }

  return sanitizeString(message);
}

/**
 * Validate search query
 */
export function validateSearchQuery(query: string | undefined): string | undefined {
  if (!query) return undefined;

  if (query.length > 500) {
    throw new SafetyError("query must be less than 500 characters", "VALIDATION_ERROR");
  }

  return sanitizeString(query);
}

/**
 * Validate railway service name
 */
export function validateRailwayService(
  service: string | undefined
): "backend" | "frontend" | "reverb" {
  if (!service) return "backend";

  if (!["backend", "frontend", "reverb"].includes(service)) {
    throw new SafetyError(
      "service must be one of: backend, frontend, reverb",
      "VALIDATION_ERROR"
    );
  }

  return service as "backend" | "frontend" | "reverb";
}
