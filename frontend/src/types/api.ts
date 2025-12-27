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
  channel_type: 'line' | 'facebook' | 'testing';
  webhook_url: string;
  webhook_forwarder_enabled: boolean;
  // Multi-model LLM configuration
  primary_chat_model: string | null;
  fallback_chat_model: string | null;
  decision_model: string | null;
  fallback_decision_model: string | null;
  // LLM Settings (legacy)
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

// Connection/Bot creation data
export interface CreateConnectionData {
  name: string;
  channel_type: 'line' | 'facebook' | 'testing';
  openrouter_api_key?: string;
  primary_chat_model?: string;
  fallback_chat_model?: string;
  decision_model?: string;
  fallback_decision_model?: string;
  channel_access_token?: string;
  channel_secret?: string;
  webhook_forwarder_enabled?: boolean;
}

// Connection/Bot update data
export interface UpdateConnectionData {
  name?: string;
  status?: 'active' | 'inactive' | 'paused';
  channel_type?: 'line' | 'facebook' | 'testing';
  openrouter_api_key?: string;
  primary_chat_model?: string;
  fallback_chat_model?: string;
  decision_model?: string;
  fallback_decision_model?: string;
  channel_access_token?: string;
  channel_secret?: string;
  webhook_forwarder_enabled?: boolean;
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

// Flow Types
export interface Flow {
  id: number;
  bot_id: number;
  name: string;
  description: string | null;
  system_prompt: string;
  model: string;
  temperature: number;
  max_tokens: number;
  agentic_mode: boolean;
  max_tool_calls: number;
  enabled_tools: string[] | null;
  knowledge_base_id: number | null;
  knowledge_base?: KnowledgeBase;
  kb_top_k: number;
  kb_similarity_threshold: number;
  language: 'th' | 'en' | 'zh' | 'ja' | 'ko';
  is_default: boolean;
  created_at: string;
  updated_at: string;
}

export interface FlowTemplate {
  id: string;
  name: string;
  description: string;
  system_prompt: string;
  temperature: number;
  language: string;
}

export interface CreateFlowData {
  name: string;
  description?: string;
  system_prompt: string;
  model?: string;
  temperature?: number;
  max_tokens?: number;
  agentic_mode?: boolean;
  max_tool_calls?: number;
  enabled_tools?: string[];
  knowledge_base_id?: number | null;
  kb_top_k?: number;
  kb_similarity_threshold?: number;
  language?: string;
  is_default?: boolean;
}

export interface UpdateFlowData extends Partial<CreateFlowData> {}

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

// Conversation Types

// Note/Memory types
export interface ConversationNote {
  id: string;
  content: string;
  type: 'note' | 'memory' | 'reminder';
  created_by: number;
  created_at: string;
  updated_at: string;
}

export interface CreateNoteData {
  content: string;
  type?: 'note' | 'memory' | 'reminder';
}

export interface UpdateNoteData {
  content: string;
  type?: 'note' | 'memory' | 'reminder';
}

export interface AddTagsData {
  tags: string[];
}

export interface BulkTagsData {
  conversation_ids: number[];
  tags: string[];
}

export interface CustomerProfile {
  id: number;
  external_id: string;
  channel_type: 'line' | 'facebook' | 'demo';
  display_name: string | null;
  picture_url: string | null;
  phone: string | null;
  email: string | null;
  interaction_count: number;
  first_interaction_at: string | null;
  last_interaction_at: string | null;
  metadata: Record<string, unknown> | null;
  tags: string[];
  notes: string | null;
  created_at: string;
  updated_at: string;
}

export interface Message {
  id: number;
  conversation_id: number;
  sender: 'user' | 'bot' | 'agent';
  content: string;
  type: 'text' | 'image' | 'file' | 'sticker' | 'location' | 'audio' | 'video' | 'template' | 'flex';
  media_url: string | null;
  media_type: string | null;
  media_metadata: Record<string, unknown> | null;
  model_used: string | null;
  prompt_tokens: number | null;
  completion_tokens: number | null;
  cost: number | null;
  external_message_id: string | null;
  reply_to_message_id: string | null;
  sentiment: string | null;
  intents: string[] | null;
  created_at: string;
  updated_at: string;
}

export interface Conversation {
  id: number;
  bot_id: number;
  customer_profile_id: number | null;
  external_customer_id: string;
  channel_type: 'line' | 'facebook' | 'demo';
  status: 'active' | 'closed' | 'handover';
  is_handover: boolean;
  assigned_user_id: number | null;
  memory_notes: ConversationNote[] | null;
  tags: string[];
  context: Record<string, unknown> | null;
  current_flow_id: number | null;
  message_count: number;
  last_message_at: string | null;
  created_at: string;
  updated_at: string;
  // Relationships
  bot?: Bot;
  customer_profile?: CustomerProfile;
  assigned_user?: User;
  current_flow?: Flow;
  messages?: Message[];
  last_message?: Message;
}

export interface ConversationStatusCounts {
  active: number;
  closed: number;
  handover: number;
  total: number;
}

export interface ConversationStats {
  total: number;
  active: number;
  closed: number;
  handover: number;
  messages_today: number;
  avg_messages_per_conversation: number;
  by_channel: Record<string, number>;
}

export interface ConversationFilters {
  status?: string | string[];
  channel_type?: string;
  is_handover?: boolean;
  assigned_user_id?: number;
  tags?: string[];
  search?: string;
  from_date?: string;
  to_date?: string;
  sort_by?: 'last_message_at' | 'created_at' | 'message_count' | 'status';
  sort_direction?: 'asc' | 'desc';
  per_page?: number;
  page?: number;
}

export interface UpdateConversationData {
  status?: 'active' | 'closed' | 'handover';
  is_handover?: boolean;
  assigned_user_id?: number | null;
  tags?: string[];
  memory_notes?: Record<string, unknown> | null;
}

// User Settings Types
export interface UserSettings {
  openrouter_configured: boolean;
  openrouter_api_key_masked: string | null;
  openrouter_model: string;
  line_configured: boolean;
  line_channel_secret_masked: string | null;
  line_channel_access_token_masked: string | null;
}

export interface UpdateOpenRouterSettings {
  api_key?: string;
  model: string;
}

export interface UpdateLineSettings {
  channel_secret?: string;
  channel_access_token?: string;
}

export interface TestConnectionResponse {
  success: boolean;
  message: string;
  bot_name?: string;
}
