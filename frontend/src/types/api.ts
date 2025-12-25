// API Response Types

export interface ApiResponse<T = unknown> {
  data: T;
  message?: string;
  meta?: PaginationMeta;
}

export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
  status: number;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface PaginatedResponse<T> extends ApiResponse<T[]> {
  meta: PaginationMeta;
}

// Auth Types
export interface User {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface LoginCredentials {
  email: string;
  password: string;
}

export interface RegisterCredentials {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export interface AuthResponse {
  user: User;
  token: string;
}

// Bot Types
export interface Bot {
  id: number;
  name: string;
  description: string | null;
  status: 'active' | 'inactive' | 'paused';
  channel_type: 'line' | 'facebook' | 'telegram';
  webhook_url: string;
  created_at: string;
  updated_at: string;
}

// Knowledge Base Types
export interface KnowledgeBase {
  id: number;
  bot_id: number;
  name: string;
  description: string | null;
  document_count: number;
  chunk_count: number;
  embedding_model: string;
  embedding_dimensions: number;
  documents?: Document[];
  created_at: string;
  updated_at: string;
}

export interface Document {
  id: number;
  knowledge_base_id: number;
  filename: string;
  original_filename: string;
  mime_type: string;
  file_size: number;
  file_size_formatted: string;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  error_message: string | null;
  chunk_count: number;
  created_at: string;
  updated_at: string;
}
