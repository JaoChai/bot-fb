/**
 * Laravel API HTTP Client
 */

import { fetch } from "undici";
import type { ServerConfig } from "../utils/config.js";
import { getApiBaseUrl } from "../utils/config.js";

export class LaravelAPIError extends Error {
  constructor(
    public status: number,
    message: string,
    public data?: unknown
  ) {
    super(message);
    this.name = "LaravelAPIError";
  }
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

export class LaravelAPIClient {
  private baseUrl: string;
  private authToken?: string;

  constructor(config: ServerConfig) {
    this.baseUrl = getApiBaseUrl(config);
    this.authToken = config.laravelAuthToken;
  }

  private async request<T>(
    method: string,
    path: string,
    data?: unknown,
    options?: { timeout?: number }
  ): Promise<T> {
    const url = `${this.baseUrl}${path}`;
    const headers: Record<string, string> = {
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
        const error = await response.json().catch(() => ({})) as Record<string, unknown>;
        throw new LaravelAPIError(
          response.status,
          (error.message as string) || `API request failed: ${response.status}`,
          error
        );
      }

      return response.json() as T;
    } catch (error) {
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
  async get<T>(path: string, options?: { timeout?: number }): Promise<T> {
    return this.request<T>("GET", path, undefined, options);
  }

  async post<T>(path: string, data?: unknown, options?: { timeout?: number }): Promise<T> {
    return this.request<T>("POST", path, data, options);
  }

  async put<T>(path: string, data?: unknown, options?: { timeout?: number }): Promise<T> {
    return this.request<T>("PUT", path, data, options);
  }

  async delete<T>(path: string, options?: { timeout?: number }): Promise<T> {
    return this.request<T>("DELETE", path, undefined, options);
  }

  // Health check
  async healthCheck(): Promise<{ status: string; [key: string]: unknown }> {
    return this.get<{ status: string; [key: string]: unknown }>("/api/health");
  }

  // ============================================
  // BOT ENDPOINTS
  // ============================================

  async listBots(): Promise<APIResponse> {
    return this.get<APIResponse>("/api/bots");
  }

  async getBot(botId: number): Promise<APIResponse> {
    return this.get<APIResponse>(`/api/bots/${botId}`);
  }

  async createBot(data: Record<string, unknown>): Promise<APIResponse> {
    return this.post<APIResponse>("/api/bots", data);
  }

  async updateBot(botId: number, data: Record<string, unknown>): Promise<APIResponse> {
    return this.put<APIResponse>(`/api/bots/${botId}`, data);
  }

  async deleteBot(botId: number): Promise<APIResponse> {
    return this.delete<APIResponse>(`/api/bots/${botId}`);
  }

  async testBot(botId: number, message: string): Promise<APIResponse> {
    return this.post<APIResponse>(`/api/bots/${botId}/test`, { message });
  }

  // ============================================
  // FLOW ENDPOINTS
  // ============================================

  async listFlows(botId: number): Promise<APIResponse> {
    return this.get<APIResponse>(`/api/bots/${botId}/flows`);
  }

  async getFlow(botId: number, flowId: number): Promise<APIResponse> {
    return this.get<APIResponse>(`/api/bots/${botId}/flows/${flowId}`);
  }

  async createFlow(botId: number, data: Record<string, unknown>): Promise<APIResponse> {
    return this.post<APIResponse>(`/api/bots/${botId}/flows`, data);
  }

  async updateFlow(botId: number, flowId: number, data: Record<string, unknown>): Promise<APIResponse> {
    return this.put<APIResponse>(`/api/bots/${botId}/flows/${flowId}`, data);
  }

  async deleteFlow(botId: number, flowId: number): Promise<APIResponse> {
    return this.delete<APIResponse>(`/api/bots/${botId}/flows/${flowId}`);
  }

  async testFlow(botId: number, flowId: number, message: string): Promise<APIResponse> {
    return this.post<APIResponse>(`/api/bots/${botId}/flows/${flowId}/test`, { message });
  }

  // ============================================
  // KNOWLEDGE BASE ENDPOINTS
  // ============================================

  async getKnowledgeBase(botId: number): Promise<APIResponse> {
    return this.get<APIResponse>(`/api/bots/${botId}/knowledge-base`);
  }

  async searchKnowledgeBase(botId: number, query: string): Promise<APIResponse> {
    return this.post<APIResponse>(`/api/bots/${botId}/knowledge-base/search`, { query });
  }

  async listDocuments(botId: number): Promise<APIResponse> {
    return this.get<APIResponse>(`/api/bots/${botId}/knowledge-base/documents`);
  }

  async deleteDocument(botId: number, documentId: number): Promise<APIResponse> {
    return this.delete<APIResponse>(`/api/bots/${botId}/knowledge-base/documents/${documentId}`);
  }

  async reprocessDocument(botId: number, documentId: number): Promise<APIResponse> {
    return this.post<APIResponse>(`/api/bots/${botId}/knowledge-base/documents/${documentId}/reprocess`);
  }

  // ============================================
  // CONVERSATION ENDPOINTS
  // ============================================

  async listConversations(botId: number, page?: number, limit?: number): Promise<APIResponse> {
    const params = new URLSearchParams();
    if (page) params.set("page", page.toString());
    if (limit) params.set("per_page", limit.toString());
    const query = params.toString() ? `?${params.toString()}` : "";
    return this.get<APIResponse>(`/api/bots/${botId}/conversations${query}`);
  }

  async getConversation(botId: number, conversationId: number): Promise<APIResponse> {
    return this.get<APIResponse>(`/api/bots/${botId}/conversations/${conversationId}`);
  }

  async sendAgentMessage(botId: number, conversationId: number, message: string): Promise<APIResponse> {
    return this.post<APIResponse>(`/api/bots/${botId}/conversations/${conversationId}/agent-message`, { message });
  }

  async toggleHandover(botId: number, conversationId: number): Promise<APIResponse> {
    return this.post<APIResponse>(`/api/bots/${botId}/conversations/${conversationId}/toggle-handover`);
  }

  async closeConversation(botId: number, conversationId: number): Promise<APIResponse> {
    return this.post<APIResponse>(`/api/bots/${botId}/conversations/${conversationId}/close`);
  }

  // ============================================
  // EVALUATION ENDPOINTS
  // ============================================

  async listEvaluations(botId: number, page?: number, status?: string): Promise<APIResponse> {
    const params = new URLSearchParams();
    if (page) params.set("page", page.toString());
    if (status) params.set("status", status);
    const query = params.toString() ? `?${params.toString()}` : "";
    return this.get<APIResponse>(`/api/bots/${botId}/evaluations${query}`);
  }

  async createEvaluation(botId: number, config: Record<string, unknown>): Promise<APIResponse> {
    return this.post<APIResponse>(`/api/bots/${botId}/evaluations`, config);
  }

  async getEvaluation(botId: number, evaluationId: number): Promise<APIResponse> {
    return this.get<APIResponse>(`/api/bots/${botId}/evaluations/${evaluationId}`);
  }

  async getEvaluationProgress(botId: number, evaluationId: number): Promise<APIResponse> {
    return this.get<APIResponse>(`/api/bots/${botId}/evaluations/${evaluationId}/progress`);
  }

  async getEvaluationTestCases(botId: number, evaluationId: number): Promise<APIResponse> {
    return this.get<APIResponse>(`/api/bots/${botId}/evaluations/${evaluationId}/test-cases`);
  }

  async getEvaluationReport(botId: number, evaluationId: number): Promise<APIResponse> {
    return this.get<APIResponse>(`/api/bots/${botId}/evaluations/${evaluationId}/report`);
  }

  async cancelEvaluation(botId: number, evaluationId: number): Promise<APIResponse> {
    return this.post<APIResponse>(`/api/bots/${botId}/evaluations/${evaluationId}/cancel`);
  }

  async retryEvaluation(botId: number, evaluationId: number): Promise<APIResponse> {
    return this.post<APIResponse>(`/api/bots/${botId}/evaluations/${evaluationId}/retry`);
  }

  async getEvaluationPersonas(): Promise<APIResponse> {
    return this.get<APIResponse>("/api/evaluation-personas");
  }

  // ============================================
  // COST ENDPOINTS
  // ============================================

  async getCostAnalytics(params?: {
    from_date?: string;
    to_date?: string;
    group_by?: string;
  }): Promise<APIResponse> {
    const searchParams = new URLSearchParams();
    if (params?.from_date) searchParams.set("from_date", params.from_date);
    if (params?.to_date) searchParams.set("to_date", params.to_date);
    if (params?.group_by) searchParams.set("group_by", params.group_by);
    const query = searchParams.toString() ? `?${searchParams.toString()}` : "";
    return this.get<APIResponse>(`/api/analytics/costs${query}`);
  }

  // ============================================
  // AUTH ENDPOINTS
  // ============================================

  async listTokens(): Promise<APIResponse> {
    return this.get<APIResponse>("/api/auth/tokens");
  }

  async revokeToken(tokenId: number): Promise<APIResponse> {
    return this.delete<APIResponse>(`/api/auth/tokens/${tokenId}`);
  }
}
