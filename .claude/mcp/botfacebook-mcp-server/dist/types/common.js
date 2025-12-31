/**
 * Common types for BotFacebook MCP Server
 */
export function createSuccessResult(data, message) {
    return {
        success: true,
        message,
        data,
        timestamp: new Date().toISOString(),
    };
}
export function createErrorResult(error, data) {
    return {
        success: false,
        error,
        data,
        timestamp: new Date().toISOString(),
    };
}
//# sourceMappingURL=common.js.map