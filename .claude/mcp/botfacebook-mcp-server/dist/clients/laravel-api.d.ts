/**
 * Laravel API HTTP Client
 */
import type { ServerConfig } from "../utils/config.js";
export declare class LaravelAPIError extends Error {
    status: number;
    data?: unknown | undefined;
    constructor(status: number, message: string, data?: unknown | undefined);
}
export interface APIResponse<T = unknown> {
    success: boolean;
    data?: T;
    message?: string;
    errors?: Record<string, string[]>;
    meta?: {
        total: number;
        page: number;
        per_page: number;
        last_page: number;
    };
}
export declare class LaravelAPIClient {
    private baseUrl;
    private authToken?;
    constructor(config: ServerConfig);
    private request;
    get<T>(path: string, options?: {
        timeout?: number;
    }): Promise<T>;
    post<T>(path: string, data?: unknown, options?: {
        timeout?: number;
    }): Promise<T>;
    put<T>(path: string, data?: unknown, options?: {
        timeout?: number;
    }): Promise<T>;
    delete<T>(path: string, options?: {
        timeout?: number;
    }): Promise<T>;
    healthCheck(): Promise<{
        status: string;
        [key: string]: unknown;
    }>;
    listBots(): Promise<APIResponse>;
    getBot(botId: number): Promise<APIResponse>;
    createBot(data: Record<string, unknown>): Promise<APIResponse>;
    updateBot(botId: number, data: Record<string, unknown>): Promise<APIResponse>;
    deleteBot(botId: number): Promise<APIResponse>;
    testBot(botId: number, message: string): Promise<APIResponse>;
    listFlows(botId: number): Promise<APIResponse>;
    getFlow(botId: number, flowId: number): Promise<APIResponse>;
    createFlow(botId: number, data: Record<string, unknown>): Promise<APIResponse>;
    updateFlow(botId: number, flowId: number, data: Record<string, unknown>): Promise<APIResponse>;
    deleteFlow(botId: number, flowId: number): Promise<APIResponse>;
    testFlow(botId: number, flowId: number, message: string): Promise<APIResponse>;
    getKnowledgeBase(botId: number): Promise<APIResponse>;
    searchKnowledgeBase(botId: number, query: string): Promise<APIResponse>;
    listDocuments(botId: number): Promise<APIResponse>;
    deleteDocument(botId: number, documentId: number): Promise<APIResponse>;
    reprocessDocument(botId: number, documentId: number): Promise<APIResponse>;
    listConversations(botId: number, page?: number, limit?: number): Promise<APIResponse>;
    getConversation(botId: number, conversationId: number): Promise<APIResponse>;
    sendAgentMessage(botId: number, conversationId: number, message: string): Promise<APIResponse>;
    toggleHandover(botId: number, conversationId: number): Promise<APIResponse>;
    closeConversation(botId: number, conversationId: number): Promise<APIResponse>;
    listEvaluations(botId: number, page?: number, status?: string): Promise<APIResponse>;
    createEvaluation(botId: number, config: Record<string, unknown>): Promise<APIResponse>;
    getEvaluation(botId: number, evaluationId: number): Promise<APIResponse>;
    getEvaluationProgress(botId: number, evaluationId: number): Promise<APIResponse>;
    getEvaluationTestCases(botId: number, evaluationId: number): Promise<APIResponse>;
    getEvaluationReport(botId: number, evaluationId: number): Promise<APIResponse>;
    cancelEvaluation(botId: number, evaluationId: number): Promise<APIResponse>;
    retryEvaluation(botId: number, evaluationId: number): Promise<APIResponse>;
    getEvaluationPersonas(): Promise<APIResponse>;
    getCostAnalytics(params?: {
        from_date?: string;
        to_date?: string;
        group_by?: string;
    }): Promise<APIResponse>;
    listTokens(): Promise<APIResponse>;
    revokeToken(tokenId: number): Promise<APIResponse>;
}
//# sourceMappingURL=laravel-api.d.ts.map