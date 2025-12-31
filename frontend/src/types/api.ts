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
  // Auto-assignment settings
  auto_assignment_enabled: boolean;
  auto_assignment_mode: 'round_robin' | 'load_balanced';
  auto_assignment_max_per_admin: number | null;
  created_at: string;
  updated_at: string;
}

// Admin Bot Assignment Types
export interface AdminBotAssignment {
  id: number;
  user_id: number;
  bot_id: number;
  assigned_by: number | null;
  user?: User;
  assigned_at: string;
  created_at: string;
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
  model: string;
  fallback_model: string | null;
  decision_model: string | null;
  fallback_decision_model: string | null;
  temperature: number;
  max_tokens: number;
  agentic_mode: boolean;
  max_tool_calls: number;
  enabled_tools: string[] | null;
  knowledge_bases?: FlowKnowledgeBase[];
  language: 'th' | 'en' | 'zh' | 'ja' | 'ko';
  is_default: boolean;
  // Agent Safety
  agent_timeout_seconds?: number;
  agent_max_cost_per_request?: number | null;
  hitl_enabled?: boolean;
  hitl_dangerous_actions?: string[];
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
  model?: string;
  fallback_model?: string;
  decision_model?: string;
  fallback_decision_model?: string;
  temperature?: number;
  max_tokens?: number;
  agentic_mode?: boolean;
  max_tool_calls?: number;
  enabled_tools?: string[];
  knowledge_bases?: CreateFlowKnowledgeBaseData[];
  language?: string;
  is_default?: boolean;
  // Agent Safety
  agent_timeout_seconds?: number;
  agent_max_cost_per_request?: number | null;
  hitl_enabled?: boolean;
  hitl_dangerous_actions?: string[];
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

export interface UpdateLineSettings {
  channel_secret?: string;
  channel_access_token?: string;
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

// Evaluation Types
export interface EvaluationPersona {
  key: string;
  name: string;
  description: string;
  example_style: string;
}

export interface EvaluationProgress {
  total: number;
  total_test_cases: number;
  completed: number;
  completed_test_cases: number;
  percent: number;
  current_phase: string;
  eta_seconds: number | null;
}

export interface EvaluationMetricScores {
  answer_relevancy: number | null;
  faithfulness: number | null;
  role_adherence: number | null;
  context_precision: number | null;
  task_completion: number | null;
}

export interface ReportStrength {
  metric: string;
  label: string;
  score: number;
  description: string;
}

export interface ReportWeakness {
  metric: string;
  label: string;
  score: number;
  description: string;
}

export interface ReportRecommendation {
  title: string;
  description: string;
  priority: 'high' | 'medium' | 'low';
}

export interface ReportPromptSuggestion {
  suggestion: string;
  example: string;
}

export interface ReportKbGap {
  topics: string[];
  test_case_id: number;
  score: number;
}

export interface EvaluationReport {
  id: number;
  evaluation_id: number;
  executive_summary: string;
  strengths: ReportStrength[];
  weaknesses: ReportWeakness[];
  recommendations: ReportRecommendation[];
  prompt_suggestions: ReportPromptSuggestion[];
  kb_gaps: ReportKbGap[];
  historical_comparison: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
}

export interface Evaluation {
  id: number;
  bot_id: number;
  flow_id: number;
  user_id: number;
  name: string;
  description: string | null;
  status: 'pending' | 'generating_tests' | 'running' | 'evaluating' | 'generating_report' | 'completed' | 'failed' | 'cancelled';
  generator_model: string;
  simulator_model: string;
  judge_model: string;
  personas: string[];
  config: Record<string, unknown>;
  overall_score: number | null;
  metric_scores: EvaluationMetricScores | null;
  progress: {
    total_test_cases: number;
    completed_test_cases: number;
    percent: number;
  };
  total_tokens_used: number;
  estimated_cost: number;
  started_at: string | null;
  completed_at: string | null;
  created_at: string;
  updated_at: string;
  error_message?: string;
  flow?: {
    id: number;
    name: string;
  };
  report?: EvaluationReport;
}

export interface EvaluationTestCaseMessage {
  id: number;
  turn_number: number;
  role: 'user' | 'assistant';
  content: string;
  rag_metadata: Record<string, unknown> | null;
  model_metadata: Record<string, unknown> | null;
  turn_scores: Record<string, unknown> | null;
}

export interface EvaluationTestCase {
  id: number;
  evaluation_id: number;
  knowledge_base_id: number | null;
  title: string;
  description: string | null;
  persona_key: string;
  test_type: 'single_turn' | 'multi_turn' | 'edge_case';
  status: 'pending' | 'running' | 'completed' | 'failed';
  scores: {
    answer_relevancy: number | null;
    faithfulness: number | null;
    role_adherence: number | null;
    context_precision: number | null;
    task_completion: number | null;
    overall: number | null;
  };
  detailed_feedback: Record<string, unknown> | null;
  expected_topics: string[] | null;
  source_chunks: string[] | null;
  messages?: EvaluationTestCaseMessage[];
  created_at: string;
  updated_at: string;
}

export interface CreateEvaluationData {
  flow_id: number;
  name?: string;
  description?: string;
  personas?: string[];
  generator_model?: string;
  simulator_model?: string;
  judge_model?: string;
  test_count?: number;
  include_multi_turn?: boolean;
  include_edge_cases?: boolean;
}

export interface EvaluationFilters {
  flow_id?: number;
  status?: string;
  per_page?: number;
  page?: number;
}

// Improvement Agent Types
export type ImprovementSessionStatus =
  | 'analyzing'
  | 'suggestions_ready'
  | 'applying'
  | 're_evaluating'
  | 'completed'
  | 'failed'
  | 'cancelled';

export type ImprovementSuggestionType = 'system_prompt' | 'kb_content';
export type ImprovementPriority = 'high' | 'medium' | 'low';

export interface ImprovementSession {
  id: number;
  evaluation_id: number;
  flow_id: number;
  bot_id: number;
  status: ImprovementSessionStatus;
  analysis_summary: string | null;
  before_score: number | null;
  after_score: number | null;
  score_improvement: number | null;
  re_evaluation_id: number | null;
  agent_model: string;
  total_tokens_used: number;
  estimated_cost: number;
  error_message: string | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string;
  updated_at: string;
  // Counts
  suggestions_count?: number;
  selected_suggestions_count?: number;
  // Relations
  evaluation?: {
    id: number;
    name: string;
    overall_score: number | null;
    status: string;
  };
  re_evaluation?: {
    id: number;
    name: string;
    overall_score: number | null;
    status: string;
  } | null;
  suggestions?: ImprovementSuggestion[];
}

export interface ImprovementSuggestion {
  id: number;
  session_id: number;
  type: ImprovementSuggestionType;
  priority: ImprovementPriority;
  priority_variant: 'destructive' | 'warning' | 'default' | 'secondary';
  confidence_score: number;
  title: string;
  description: string | null;
  is_selected: boolean;
  is_applied: boolean;
  applied_at: string | null;
  source_metric: string | null;
  source_test_case_ids: number[] | null;
  created_at: string;
  // System prompt specific
  current_value?: string;
  suggested_value?: string;
  diff_summary?: string;
  // KB content specific
  target_knowledge_base_id?: number;
  kb_content_title?: string;
  kb_content_body?: string;
  related_topics?: string[];
}

export interface ImprovementPreview {
  prompt_changes: {
    id: number;
    title: string;
    current: string | null;
    suggested: string | null;
    diff_summary: string | null;
  }[];
  kb_additions: {
    id: number;
    title: string;
    content: string | null;
    related_topics: string[] | null;
  }[];
  summary: {
    total_selected: number;
    prompt_updates: number;
    kb_documents: number;
  };
}

// Dashboard Types
export interface DashboardSummary {
  total_bots: number;
  active_bots: number;
  total_conversations: number;
  active_conversations: number;
  handover_conversations: number;
  messages_today: number;
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
  latest_evaluation: {
    id: number;
    overall_score: number | null;
    status: string;
    completed_at: string | null;
  } | null;
}

export interface DashboardHandoverAlert {
  id: number;
  bot_id: number;
  bot_name: string;
  customer_name: string;
  waiting_since: string;
}

export interface DashboardEvaluationAlert {
  id: number;
  bot_id: number;
  bot_name: string;
  status: string;
  progress_percent: number;
  name: string;
}

export interface DashboardImprovementAlert {
  id: number;
  bot_id: number;
  bot_name: string;
  status: string;
  suggestions_count: number;
}

export interface DashboardAlerts {
  handover_conversations: DashboardHandoverAlert[];
  running_evaluations: DashboardEvaluationAlert[];
  pending_improvements: DashboardImprovementAlert[];
}

export type DashboardActivityType =
  | 'evaluation_started'
  | 'evaluation_completed'
  | 'evaluation_failed'
  | 'handover_started'
  | 'handover_resolved'
  | 'improvement_started'
  | 'improvement_applied'
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
