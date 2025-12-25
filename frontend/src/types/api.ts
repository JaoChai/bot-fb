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
  // LLM Settings
  llm_model: string | null;
  llm_fallback_model: string | null;
  system_prompt: string | null;
  llm_temperature: number;
  llm_max_tokens: number;
  context_window: number;
  // Knowledge Base Settings
  kb_enabled: boolean;
  kb_relevance_threshold: number;
  kb_max_results: number;
  // Stats
  total_conversations: number;
  total_messages: number;
  last_active_at: string | null;
  // Relationships
  settings?: BotSettings;
  created_at: string;
  updated_at: string;
}

// Bot Settings Types
export interface BotSettings {
  id: number;
  bot_id: number;
  // Usage limits
  daily_message_limit: number;
  per_user_limit: number;
  rate_limit_per_minute: number;
  max_tokens_per_response: number;
  // HITL settings
  hitl_enabled: boolean;
  hitl_triggers: string[] | null;
  // Response hours
  response_hours_enabled: boolean;
  response_hours: Record<string, { start: string; end: string }> | null;
  offline_message: string | null;
  // Auto-responses
  welcome_message: string | null;
  fallback_message: string | null;
  typing_indicator: boolean;
  typing_delay_ms: number;
  // Content moderation
  content_filter_enabled: boolean;
  blocked_keywords: string[] | null;
  // Analytics
  analytics_enabled: boolean;
  save_conversations: boolean;
  // Language and style
  language: 'th' | 'en' | 'zh' | 'ja' | 'ko';
  response_style: 'professional' | 'casual' | 'friendly' | 'formal';
  // Conversation management
  auto_archive_days: number | null;
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

// Semantic Search Types
export interface SearchResult {
  id: number;
  document_id: number;
  document_name: string;
  content: string;
  chunk_index: number;
  similarity: number;
  metadata: Record<string, unknown> | null;
}

export interface SearchResponse {
  query: string;
  results: SearchResult[];
  count: number;
  message?: string;
}
