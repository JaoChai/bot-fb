/**
 * Input validators and sanitizers
 */
/**
 * Validate and sanitize tinker code
 */
export declare function validateTinkerCode(code: string): void;
/**
 * Validate bot ID
 */
export declare function validateBotId(botId: unknown, required?: boolean): number | undefined;
/**
 * Validate pagination params
 */
export declare function validatePagination(page?: unknown, limit?: unknown): {
    page: number;
    limit: number;
};
/**
 * Validate date string
 */
export declare function validateDate(date: string | undefined): string | undefined;
/**
 * Validate test count for evaluation
 */
export declare function validateTestCount(count: number | undefined): number;
/**
 * Sanitize string input
 */
export declare function sanitizeString(input: string | undefined): string | undefined;
/**
 * Validate message content
 */
export declare function validateMessage(message: string | undefined, required?: boolean): string | undefined;
/**
 * Validate search query
 */
export declare function validateSearchQuery(query: string | undefined): string | undefined;
/**
 * Validate railway service name
 */
export declare function validateRailwayService(service: string | undefined): "backend" | "frontend" | "reverb";
//# sourceMappingURL=validators.d.ts.map