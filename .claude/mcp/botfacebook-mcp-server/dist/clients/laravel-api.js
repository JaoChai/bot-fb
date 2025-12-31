/**
 * Laravel API HTTP Client
 */
import { fetch } from "undici";
import { getApiBaseUrl } from "../utils/config.js";
export class LaravelAPIError extends Error {
    status;
    data;
    constructor(status, message, data) {
        super(message);
        this.status = status;
        this.data = data;
        this.name = "LaravelAPIError";
    }
}
export class LaravelAPIClient {
    baseUrl;
    authToken;
    constructor(config) {
        this.baseUrl = getApiBaseUrl(config);
        this.authToken = config.laravelAuthToken;
    }
    async request(method, path, data, options) {
        const url = `${this.baseUrl}${path}`;
        const headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
        };
        if (this.authToken) {
            headers["Authorization"] = `Bearer ${this.authToken}`;
        }
        const controller = new AbortController();
        const timeout = options?.timeout || 30000;
        const timeoutId = setTimeout(() => controller.abort(), timeout);
        try {
            const response = await fetch(url, {
                method,
                headers,
                body: data ? JSON.stringify(data) : undefined,
                signal: controller.signal,
            });
            clearTimeout(timeoutId);
            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new LaravelAPIError(response.status, error.message || `API request failed: ${response.status}`, error);
            }
            return response.json();
        }
        catch (error) {
            clearTimeout(timeoutId);
            if (error instanceof LaravelAPIError) {
                throw error;
            }
            if (error instanceof Error && error.name === "AbortError") {
                throw new LaravelAPIError(408, "Request timeout");
            }
            throw new LaravelAPIError(500, String(error));
        }
    }
    // Convenience methods
    async get(path, options) {
        return this.request("GET", path, undefined, options);
    }
    async post(path, data, options) {
        return this.request("POST", path, data, options);
    }
    async put(path, data, options) {
        return this.request("PUT", path, data, options);
    }
    async delete(path, options) {
        return this.request("DELETE", path, undefined, options);
    }
    // Health check
    async healthCheck() {
        return this.get("/api/health");
    }
    // ============================================
    // BOT ENDPOINTS
    // ============================================
    async listBots() {
        return this.get("/api/bots");
    }
    async getBot(botId) {
        return this.get(`/api/bots/${botId}`);
    }
    async createBot(data) {
        return this.post("/api/bots", data);
    }
    async updateBot(botId, data) {
        return this.put(`/api/bots/${botId}`, data);
    }
    async deleteBot(botId) {
        return this.delete(`/api/bots/${botId}`);
    }
    async testBot(botId, message) {
        return this.post(`/api/bots/${botId}/test`, { message });
    }
    // ============================================
    // FLOW ENDPOINTS
    // ============================================
    async listFlows(botId) {
        return this.get(`/api/bots/${botId}/flows`);
    }
    async getFlow(botId, flowId) {
        return this.get(`/api/bots/${botId}/flows/${flowId}`);
    }
    async createFlow(botId, data) {
        return this.post(`/api/bots/${botId}/flows`, data);
    }
    async updateFlow(botId, flowId, data) {
        return this.put(`/api/bots/${botId}/flows/${flowId}`, data);
    }
    async deleteFlow(botId, flowId) {
        return this.delete(`/api/bots/${botId}/flows/${flowId}`);
    }
    async testFlow(botId, flowId, message) {
        return this.post(`/api/bots/${botId}/flows/${flowId}/test`, { message });
    }
    // ============================================
    // KNOWLEDGE BASE ENDPOINTS
    // ============================================
    async getKnowledgeBase(botId) {
        return this.get(`/api/bots/${botId}/knowledge-base`);
    }
    async searchKnowledgeBase(botId, query) {
        return this.post(`/api/bots/${botId}/knowledge-base/search`, { query });
    }
    async listDocuments(botId) {
        return this.get(`/api/bots/${botId}/knowledge-base/documents`);
    }
    async deleteDocument(botId, documentId) {
        return this.delete(`/api/bots/${botId}/knowledge-base/documents/${documentId}`);
    }
    async reprocessDocument(botId, documentId) {
        return this.post(`/api/bots/${botId}/knowledge-base/documents/${documentId}/reprocess`);
    }
    // ============================================
    // CONVERSATION ENDPOINTS
    // ============================================
    async listConversations(botId, page, limit) {
        const params = new URLSearchParams();
        if (page)
            params.set("page", page.toString());
        if (limit)
            params.set("per_page", limit.toString());
        const query = params.toString() ? `?${params.toString()}` : "";
        return this.get(`/api/bots/${botId}/conversations${query}`);
    }
    async getConversation(botId, conversationId) {
        return this.get(`/api/bots/${botId}/conversations/${conversationId}`);
    }
    async sendAgentMessage(botId, conversationId, message) {
        return this.post(`/api/bots/${botId}/conversations/${conversationId}/agent-message`, { message });
    }
    async toggleHandover(botId, conversationId) {
        return this.post(`/api/bots/${botId}/conversations/${conversationId}/toggle-handover`);
    }
    async closeConversation(botId, conversationId) {
        return this.post(`/api/bots/${botId}/conversations/${conversationId}/close`);
    }
    // ============================================
    // EVALUATION ENDPOINTS
    // ============================================
    async listEvaluations(botId, page, status) {
        const params = new URLSearchParams();
        if (page)
            params.set("page", page.toString());
        if (status)
            params.set("status", status);
        const query = params.toString() ? `?${params.toString()}` : "";
        return this.get(`/api/bots/${botId}/evaluations${query}`);
    }
    async createEvaluation(botId, config) {
        return this.post(`/api/bots/${botId}/evaluations`, config);
    }
    async getEvaluation(botId, evaluationId) {
        return this.get(`/api/bots/${botId}/evaluations/${evaluationId}`);
    }
    async getEvaluationProgress(botId, evaluationId) {
        return this.get(`/api/bots/${botId}/evaluations/${evaluationId}/progress`);
    }
    async getEvaluationTestCases(botId, evaluationId) {
        return this.get(`/api/bots/${botId}/evaluations/${evaluationId}/test-cases`);
    }
    async getEvaluationReport(botId, evaluationId) {
        return this.get(`/api/bots/${botId}/evaluations/${evaluationId}/report`);
    }
    async cancelEvaluation(botId, evaluationId) {
        return this.post(`/api/bots/${botId}/evaluations/${evaluationId}/cancel`);
    }
    async retryEvaluation(botId, evaluationId) {
        return this.post(`/api/bots/${botId}/evaluations/${evaluationId}/retry`);
    }
    async getEvaluationPersonas() {
        return this.get("/api/evaluation-personas");
    }
    // ============================================
    // COST ENDPOINTS
    // ============================================
    async getCostAnalytics(params) {
        const searchParams = new URLSearchParams();
        if (params?.from_date)
            searchParams.set("from_date", params.from_date);
        if (params?.to_date)
            searchParams.set("to_date", params.to_date);
        if (params?.group_by)
            searchParams.set("group_by", params.group_by);
        const query = searchParams.toString() ? `?${searchParams.toString()}` : "";
        return this.get(`/api/analytics/costs${query}`);
    }
    // ============================================
    // AUTH ENDPOINTS
    // ============================================
    async listTokens() {
        return this.get("/api/auth/tokens");
    }
    async revokeToken(tokenId) {
        return this.delete(`/api/auth/tokens/${tokenId}`);
    }
}
//# sourceMappingURL=laravel-api.js.map