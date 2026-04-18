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
export type UserRole = 'owner' | 'admin';

export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
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
  channel_type: 'line' | 'facebook' | 'testing' | 'telegram';
  webhook_url: string;
  webhook_forwarder_enabled: boolean;
  auto_handover: boolean;
  // Multi-model LLM configuration (API key now in User Settings)
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
  // Smart Routing (Confidence Cascade)
  use_confidence_cascade: boolean;
  cascade_cheap_model: string | null;
  cascade_expensive_model: string | null;
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
  // Owner info (loaded for admins)
  user?: {
    id: number;
    name: string;
    email: string;
  };
  // Admins (loaded for owners)
  admins?: User[];
  created_at: string;
  updated_at: string;
}

// Connection/Bot creation data (API key now in User Settings)
export interface CreateConnectionData {
  name: string;
  channel_type: 'line' | 'facebook' | 'testing' | 'telegram';
  primary_chat_model?: string;
  fallback_chat_model?: string;
  decision_model?: string;
  fallback_decision_model?: string;
  channel_access_token?: string;
  channel_secret?: string;
  webhook_forwarder_enabled?: boolean;
  auto_handover?: boolean;
  // Smart Routing (Confidence Cascade)
  use_confidence_cascade?: boolean;
  cascade_cheap_model?: string;
  cascade_expensive_model?: string;
}

// Connection/Bot update data (API key now in User Settings)
export interface UpdateConnectionData {
  name?: string;
  status?: 'active' | 'inactive' | 'paused';
  channel_type?: 'line' | 'facebook' | 'testing' | 'telegram';
  primary_chat_model?: string;
  fallback_chat_model?: string;
  decision_model?: string;
  fallback_decision_model?: string;
  channel_access_token?: string;
  channel_secret?: string;
  webhook_forwarder_enabled?: boolean;
  auto_handover?: boolean;
  // Smart Routing (Confidence Cascade)
  use_confidence_cascade?: boolean;
  cascade_cheap_model?: string;
  cascade_expensive_model?: string;
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
  // Lead Recovery settings
  lead_recovery_enabled: boolean;
  lead_recovery_timeout_hours: number;
  lead_recovery_mode: 'static' | 'ai';
  lead_recovery_message: string | null;
  lead_recovery_max_attempts: number;
  // Response hours
  response_hours_enabled: boolean;
  response_hours: Record<string, { start: string; end: string }[]> | null;
  response_hours_timezone: string;
  offline_message: string | null;
  // Auto-responses
  welcome_message: string | null;
  fallback_message: string | null;
  rate_limit_bot_message: string | null;
  rate_limit_user_message: string | null;
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
  // Multiple bubbles
  multiple_bubbles_enabled: boolean;
  multiple_bubbles_min: number;
  multiple_bubbles_max: number;
  multiple_bubbles_delimiter: string | null;
  wait_multiple_bubbles_enabled: boolean;
  wait_multiple_bubbles_ms: number;
  // Smart aggregation
  smart_aggregation_enabled: boolean;
  smart_min_wait_ms: number;
  smart_max_wait_ms: number;
  smart_early_trigger_enabled: boolean;
  smart_per_user_learning_enabled: boolean;
  // Reply sticker
  reply_sticker_enabled: boolean;
  reply_sticker_message: string | null;
  reply_sticker_mode: string;
  reply_sticker_ai_prompt: string | null;
  // Auto-assignment settings
  auto_assignment_enabled: boolean;
  auto_assignment_mode: 'round_robin' | 'load_balanced';
  auto_assignment_max_per_admin: number | null;
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
export interface FlowKnowledgeBase {
  id: number;
  name: string;
  kb_top_k: number;
  kb_similarity_threshold: number;
}

export interface Flow {
  id: number;
  bot_id: number;
  name: string;
  description: string | null;
  system_prompt: string;
  temperature: number;
  max_tokens: number;
  knowledge_bases?: FlowKnowledgeBase[];
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

export interface CreateFlowKnowledgeBaseData {
  id: number;
  kb_top_k?: number;
  kb_similarity_threshold?: number;
}

export interface CreateFlowData {
  name: string;
  description?: string;
  system_prompt: string;
  temperature?: number;
  max_tokens?: number;
  knowledge_bases?: CreateFlowKnowledgeBaseData[];
  is_default?: boolean;
}

export type UpdateFlowData = Partial<CreateFlowData>;

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
  channel_type: 'line' | 'facebook' | 'demo' | 'telegram';
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

export interface VipCustomer {
  customer_profile_id: number;
  display_name: string | null;
  picture_url: string | null;
  channel_type: string | null;
  order_count: number;
  total_amount: number;
  last_order_at: string | null;
  note_content: string;
  note_source: 'vip_auto' | 'vip_manual';
  bot_id: number;
}

export interface VipCustomersResponse {
  data: VipCustomer[];
}

export interface Message {
  id: number;
  conversation_id: number;
  sender: 'user' | 'bot' | 'agent';
  content: string;
  type: 'text' | 'image' | 'file' | 'sticker' | 'location' | 'audio' | 'video' | 'template' | 'flex' | 'photo' | 'voice' | 'contact' | 'poll';
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
  // Enhanced usage tracking (OpenRouter Best Practice)
  cached_tokens?: number | null;
  reasoning_tokens?: number | null;
  reasoning_content?: string | null;
  created_at: string;
  updated_at: string;
}

export interface Conversation {
  id: number;
  bot_id: number;
  customer_profile_id: number | null;
  external_customer_id: string;
  channel_type: 'line' | 'facebook' | 'demo' | 'telegram';
  status: 'active' | 'closed' | 'handover';
  is_handover: boolean;
  // Telegram-specific fields
  telegram_chat_type?: 'private' | 'group' | 'supergroup' | 'channel' | null;
  telegram_chat_title?: string | null;
  assigned_user_id: number | null;
  assignment_method: 'manual' | 'claimed' | 'auto' | null;
  assigned_at: string | null;
  memory_notes: ConversationNote[] | null;
  tags: string[];
  context: Record<string, unknown> | null;
  current_flow_id: number | null;
  message_count: number;
  unread_count: number;
  last_message_at: string | null;
  bot_auto_enable_at: string | null;
  bot_auto_enable_remaining_seconds: number | null;
  context_cleared_at: string | null;
  created_at: string;
  updated_at: string;
  // Computed fields
  needs_response?: boolean;
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
  needs_response?: number;
  waiting_customer?: number;
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
  telegram_chat_type?: string | string[];
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

export interface TestConnectionResponse {
  success: boolean;
  message: string;
  bot_name?: string;
}

// Cost Analytics Types
export interface CostSummary {
  total_responses: number;
  total_cost: number;
  total_prompt_tokens: number;
  total_completion_tokens: number;
  avg_cost_per_response: number;
  today_cost: number;
  week_cost: number;
  month_cost: number;
  // Enhanced cost tracking (OpenRouter Best Practice)
  total_actual_cost?: number;
  total_cached_tokens?: number;
  total_reasoning_tokens?: number;
  cost_savings?: number | null;
  enhanced_data_coverage?: number;
}

export interface CostByModel {
  model_used: string | null;
  response_count: number;
  total_cost: number;
  prompt_tokens: number;
  completion_tokens: number;
}

export interface CostTimeSeries {
  period: string;
  response_count: number;
  total_cost: number;
  prompt_tokens: number;
  completion_tokens: number;
}

export interface CostByBot {
  bot_id: number;
  bot_name: string;
  response_count: number;
  total_cost: number;
}

export interface CostAnalyticsData {
  summary: CostSummary;
  by_model: CostByModel[];
  time_series: CostTimeSeries[];
  by_bot: CostByBot[] | null;
  period: {
    from: string;
    to: string;
    group_by: 'day' | 'week' | 'month';
  };
}

export interface CostAnalyticsFilters {
  from_date?: string;
  to_date?: string;
  group_by?: 'day' | 'week' | 'month';
  bot_id?: number;
}

// Dashboard Types
export interface DashboardSummary {
  total_bots: number;
  active_bots: number;
  total_conversations: number;
  active_conversations: number;
  handover_conversations: number;
  messages_today: number;
  messages_yesterday?: number;
  vip_customers: number;
  vip_total_spent: number;
}

export interface DashboardBotSummary {
  id: number;
  name: string;
  status: 'active' | 'inactive' | 'paused';
  channel_type: 'line' | 'facebook' | 'testing' | 'telegram';
  last_active_at: string | null;
  conversation_count: number;
  active_conversations: number;
  handover_count: number;
  messages_today: number;
}

export interface DashboardHandoverAlert {
  id: number;
  bot_id: number;
  bot_name: string;
  customer_name: string;
  waiting_since: string;
}

export interface DashboardAlerts {
  handover_conversations: DashboardHandoverAlert[];
}

export type DashboardActivityType =
  | 'handover_started'
  | 'handover_resolved'
  | 'bot_created'
  | 'bot_updated'
  | 'conversation_started';

export interface DashboardActivity {
  id: number;
  type: DashboardActivityType;
  title: string;
  description: string | null;
  bot_id: number | null;
  bot_name: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string;
}

export interface DashboardData {
  summary: DashboardSummary;
  bots: DashboardBotSummary[];
  alerts: DashboardAlerts;
  recent_activity: DashboardActivity[];
}

// Product Stock
export interface ProductStock {
  id: number;
  name: string;
  slug: string;
  aliases: string[];
  in_stock: boolean;
  display_order: number;
  created_at: string;
  updated_at: string;
}

// Orders
export interface OrderItem {
  id: number;
  order_id: number;
  product_name: string;
  category: string;
  quantity: number;
  unit_price: number | null;
  subtotal: number | null;
}

export interface Order {
  id: number;
  bot_id: number;
  conversation_id: number;
  customer_profile_id: number | null;
  message_id: number | null;
  total_amount: number;
  payment_method: string | null;
  status: string;
  channel_type: string | null;
  raw_extraction: Record<string, unknown> | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
  items: OrderItem[];
  customer_profile?: {
    id: number;
    display_name: string | null;
    picture_url: string | null;
  };
}

export interface OrderSummary {
  total_orders: number;
  total_revenue: number;
  today_orders: number;
  today_revenue: number;
  yesterday_orders?: number;
  yesterday_revenue?: number;
  this_week_orders: number;
  this_week_revenue: number;
  this_month_orders: number;
  this_month_revenue: number;
  all_time_orders?: number;
  all_time_revenue?: number;
}

export interface OrderTimeSeries {
  date: string;
  orders: number;
  revenue: number;
}

export interface OrderSummaryData {
  summary: OrderSummary;
  time_series: OrderTimeSeries[];
}

export interface CustomerOrderBreakdown {
  customer_profile_id: number;
  customer_name: string;
  picture_url: string | null;
  order_count: number;
  total_spent: number;
  last_order_at: string;
  is_vip: boolean;
}

export interface ProductOrderBreakdown {
  product_name: string;
  category: string;
  quantity_sold: number;
  total_revenue: number;
  order_count: number;
}

export interface OrderFilters {
  bot_id?: number;
  start_date?: string;
  end_date?: string;
  status?: string;
  category?: string;
  customer_profile_id?: number;
  search?: string;
  page?: number;
  per_page?: number;
}
