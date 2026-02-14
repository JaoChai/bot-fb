import axios, { type AxiosError, type InternalAxiosRequestConfig } from 'axios';
import type { ApiError } from '@/types/api';
import { getEcho } from './echo';

let cachedSocketId: string | null = null;
let socketIdTimestamp = 0;
const SOCKET_ID_TTL = 5000;

function getSocketId(): string | null {
  const now = Date.now();
  if (cachedSocketId && now - socketIdTimestamp < SOCKET_ID_TTL) {
    return cachedSocketId;
  }
  try {
    cachedSocketId = getEcho()?.socketId() ?? null;
  } catch {
    cachedSocketId = null;
  }
  socketIdTimestamp = now;
  return cachedSocketId;
}

let API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

// Ensure API_BASE_URL ends with /api to fix auth routing
if (!API_BASE_URL.endsWith('/api')) {
  API_BASE_URL = `${API_BASE_URL}/api`;
}

export const api = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
  withCredentials: true, // For Sanctum cookie-based auth
});

// Request interceptor - attach auth token and socket ID
api.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    const token = localStorage.getItem('auth_token');
    if (token && config.headers) {
      config.headers.Authorization = `Bearer ${token}`;
    }

    // Add X-Socket-ID header for Laravel's toOthers() to work
    const socketId = getSocketId();
    if (socketId && config.headers) {
      config.headers['X-Socket-ID'] = socketId;
    }

    return config;
  },
  (error) => Promise.reject(error)
);

// Response interceptor - handle errors globally
api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiError>) => {
    const apiError: ApiError = {
      message: error.response?.data?.message || error.message || 'An error occurred',
      errors: error.response?.data?.errors,
      status: error.response?.status || 500,
    };

    // Handle 401 - unauthorized (token expired)
    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token');
      // Let the auth store handle redirect
      window.dispatchEvent(new CustomEvent('auth:logout'));
    }

    return Promise.reject(apiError);
  }
);

// Helper functions for common requests
export const apiGet = <T>(url: string) =>
  api.get<T>(url).then((res) => res.data);

export const apiPost = <T>(url: string, data?: unknown) =>
  api.post<T>(url, data).then((res) => res.data);

export const apiPut = <T>(url: string, data?: unknown) =>
  api.put<T>(url, data).then((res) => res.data);

export const apiPatch = <T>(url: string, data?: unknown) =>
  api.patch<T>(url, data).then((res) => res.data);

export const apiDelete = <T>(url: string) =>
  api.delete<T>(url).then((res) => res.data);

// Agent Approval (HITL) actions
export async function approveAgentAction(approvalId: string, reason?: string): Promise<void> {
  await api.post(`/agent-approvals/${approvalId}/approve`, { reason });
}

export async function rejectAgentAction(approvalId: string, reason?: string): Promise<void> {
  await api.post(`/agent-approvals/${approvalId}/reject`, { reason });
}

/**
 * Extract error message from various error types
 * Handles ApiError objects, Axios errors, and standard Error objects
 */
export function getErrorMessage(error: unknown): string {
  if (!error) return 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ';

  // ApiError from our interceptor
  if (typeof error === 'object' && 'message' in error) {
    return (error as { message: string }).message;
  }

  // Standard Error object
  if (error instanceof Error) {
    return error.message;
  }

  // Already a string
  if (typeof error === 'string') {
    return error;
  }

  return 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ';
}

