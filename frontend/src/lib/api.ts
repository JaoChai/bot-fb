import axios, { type AxiosError, type InternalAxiosRequestConfig } from 'axios';
import type { ApiError } from '@/types/api';

let API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

// Ensure API_BASE_URL ends with /api
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

// Request interceptor - attach auth token
api.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    const token = localStorage.getItem('auth_token');
    if (token && config.headers) {
      config.headers.Authorization = `Bearer ${token}`;
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

export default api;
