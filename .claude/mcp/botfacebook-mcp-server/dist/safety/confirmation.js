/**
 * Safety confirmation checks for dangerous actions
 */
// Actions that require explicit confirmation
const DANGEROUS_ACTIONS = {
    fix: [
        "migrate",
        "migrate_fresh",
        "seed",
        "restart_queue",
    ],
    bot_manage: [
        "delete_bot",
        "delete_flow",
        "delete_document",
    ],
    execute: [
        "deploy_backend",
        "deploy_frontend",
        "revoke_token",
    ],
};
// Actions with rate limits
const RATE_LIMITS = {
    "execute:tinker": { max: 10, window: 60000 }, // 10/min
    "bot_manage:test_bot": { max: 20, window: 60000 }, // 20/min
    "fix:migrate_fresh": { max: 1, window: 3600000 }, // 1/hour
};
// Track rate limit counts
const rateLimitState = new Map();
export class SafetyError extends Error {
    code;
    constructor(message, code = "SAFETY_ERROR") {
        super(message);
        this.code = code;
        this.name = "SafetyError";
    }
}
/**
 * Check if an action requires confirmation
 */
export function requiresConfirmation(tool, action) {
    const dangerousForTool = DANGEROUS_ACTIONS[tool];
    return dangerousForTool?.includes(action) ?? false;
}
/**
 * Validate that dangerous action has confirmation
 */
export function validateConfirmation(tool, action, confirm) {
    if (requiresConfirmation(tool, action) && confirm !== true) {
        throw new SafetyError(`Action "${action}" requires explicit confirmation. Set confirm: true to proceed.`, "CONFIRMATION_REQUIRED");
    }
}
/**
 * Check rate limit for an action
 */
export function checkRateLimit(tool, action) {
    const key = `${tool}:${action}`;
    const limit = RATE_LIMITS[key];
    if (!limit)
        return;
    const now = Date.now();
    const state = rateLimitState.get(key);
    if (!state || state.resetAt < now) {
        rateLimitState.set(key, { count: 1, resetAt: now + limit.window });
        return;
    }
    if (state.count >= limit.max) {
        const waitSeconds = Math.ceil((state.resetAt - now) / 1000);
        throw new SafetyError(`Rate limit exceeded for ${key}. Max ${limit.max} requests per ${limit.window / 1000}s. Wait ${waitSeconds}s.`, "RATE_LIMIT_EXCEEDED");
    }
    state.count++;
}
/**
 * Get danger level for an action
 */
export function getDangerLevel(tool, action) {
    const dangerous = DANGEROUS_ACTIONS[tool];
    if (!dangerous?.includes(action)) {
        return "safe";
    }
    // Extra dangerous actions
    if (["migrate_fresh", "seed"].includes(action)) {
        return "dangerous";
    }
    return "moderate";
}
/**
 * Get warning message for dangerous action
 */
export function getDangerWarning(tool, action) {
    const warnings = {
        "fix:migrate_fresh": "This will DROP all tables and re-run migrations. All data will be lost!",
        "fix:seed": "This will populate database with seed data. May overwrite existing data.",
        "bot_manage:delete_bot": "This will permanently delete the bot and all associated data.",
        "bot_manage:delete_flow": "This will permanently delete the flow.",
        "bot_manage:delete_document": "This will permanently delete the document and its embeddings.",
        "execute:deploy_backend": "This will deploy new code to production backend.",
        "execute:deploy_frontend": "This will deploy new code to production frontend.",
        "execute:revoke_token": "This will revoke the authentication token. User will be logged out.",
    };
    return warnings[`${tool}:${action}`] || null;
}
//# sourceMappingURL=confirmation.js.map