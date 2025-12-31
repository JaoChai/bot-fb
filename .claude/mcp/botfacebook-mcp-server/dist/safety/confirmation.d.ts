/**
 * Safety confirmation checks for dangerous actions
 */
export declare class SafetyError extends Error {
    code: string;
    constructor(message: string, code?: string);
}
/**
 * Check if an action requires confirmation
 */
export declare function requiresConfirmation(tool: "fix" | "bot_manage" | "execute", action: string): boolean;
/**
 * Validate that dangerous action has confirmation
 */
export declare function validateConfirmation(tool: "fix" | "bot_manage" | "execute", action: string, confirm?: boolean): void;
/**
 * Check rate limit for an action
 */
export declare function checkRateLimit(tool: string, action: string): void;
/**
 * Get danger level for an action
 */
export declare function getDangerLevel(tool: "fix" | "bot_manage" | "execute", action: string): "safe" | "moderate" | "dangerous";
/**
 * Get warning message for dangerous action
 */
export declare function getDangerWarning(tool: string, action: string): string | null;
//# sourceMappingURL=confirmation.d.ts.map