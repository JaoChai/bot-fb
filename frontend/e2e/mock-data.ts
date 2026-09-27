// E2E mock data. Shapes copied from src/types/api.ts (User, Bot,
// CustomerProfile, Conversation, Message, Flow, PaginationMeta) and the flat
// AuthResponse contract (backend AuthController returns `user` + `token` at
// the top level) — keep in sync with both.
import type {
  Bot,
  Conversation,
  CustomerProfile,
  DashboardBotSummary,
  Flow,
  Message,
  PaginationMeta,
} from '../src/types/api'

export const user = {
  id: 1,
  name: 'E2E Owner',
  email: 'e2e@example.com',
  role: 'owner',
  email_verified_at: '2026-01-01T00:00:00.000000Z',
  created_at: '2026-01-01T00:00:00.000000Z',
  updated_at: '2026-01-01T00:00:00.000000Z',
} as const

export const token = 'e2e-token'

// Laravel-style pagination meta shared by every list endpoint.
export const pagination: PaginationMeta = {
  current_page: 1,
  last_page: 1,
  per_page: 30,
  total: 1,
}

export const bot = {
  id: 1,
  name: 'E2E Bot',
  description: null,
  status: 'active',
  channel_type: 'testing',
  webhook_url: 'https://e2e.example.com/webhook',
  auto_handover: false,
  auto_delivery_enabled: false,
  primary_chat_model: null,
  fallback_chat_model: null,
  utility_model: null,
  reasoning_effort: null,
  system_prompt: null,
  llm_temperature: 0.7,
  llm_max_tokens: 2048,
  context_window: 8192,
  kb_enabled: false,
  kb_relevance_threshold: 0.5,
  kb_max_results: 5,
  total_conversations: 1,
  total_messages: 1,
  last_active_at: '2026-01-01T00:00:00.000000Z',
  created_at: '2026-01-01T00:00:00.000000Z',
  updated_at: '2026-01-01T00:00:00.000000Z',
} satisfies Bot

// Dashboard summary row (DashboardData.bots[] in src/types/api.ts) — narrower
// than `bot`, which is a full Bot.
export const dashboardBot = {
  id: 1,
  name: 'E2E Bot',
  status: 'active',
  channel_type: 'testing',
  last_active_at: '2026-01-01T00:00:00.000000Z',
  conversation_count: 1,
  active_conversations: 1,
  handover_count: 0,
  messages_today: 1,
} satisfies DashboardBotSummary

export const customerProfile = {
  id: 1,
  external_id: 'e2e-customer-1',
  channel_type: 'line',
  display_name: 'E2E Customer',
  picture_url: null,
  phone: null,
  email: null,
  interaction_count: 1,
  first_interaction_at: '2026-01-01T00:00:00.000000Z',
  last_interaction_at: '2026-01-01T00:00:00.000000Z',
  metadata: null,
  tags: [],
  notes: null,
  created_at: '2026-01-01T00:00:00.000000Z',
  updated_at: '2026-01-01T00:00:00.000000Z',
} satisfies CustomerProfile

export const message = {
  id: 1,
  conversation_id: 1,
  sender: 'user',
  content: 'สวัสดีครับ',
  type: 'text',
  media_url: null,
  media_type: null,
  media_metadata: null,
  model_used: null,
  prompt_tokens: null,
  completion_tokens: null,
  cost: null,
  external_message_id: null,
  reply_to_message_id: null,
  sentiment: null,
  intents: null,
  created_at: '2026-01-01T00:00:00.000000Z',
  updated_at: '2026-01-01T00:00:00.000000Z',
} satisfies Message

// No `last_message`: the list row then falls back to the "N messages" preview,
// so 'สวัสดีครับ' stays unique to the chat bubble for the visibility assert.
export const conversation = {
  id: 1,
  bot_id: 1,
  customer_profile_id: 1,
  external_customer_id: 'e2e-customer-1',
  channel_type: 'line',
  status: 'active',
  is_handover: false,
  assigned_user_id: null,
  assignment_method: null,
  assigned_at: null,
  memory_notes: null,
  tags: [],
  context: null,
  current_flow_id: null,
  message_count: 1,
  unread_count: 0,
  last_message_at: '2026-01-01T00:00:00.000000Z',
  bot_auto_enable_at: null,
  bot_auto_enable_remaining_seconds: null,
  context_cleared_at: null,
  created_at: '2026-01-01T00:00:00.000000Z',
  updated_at: '2026-01-01T00:00:00.000000Z',
  customer_profile: customerProfile,
} satisfies Conversation

export const flow = {
  id: 1,
  bot_id: 1,
  name: 'E2E Flow',
  description: null,
  system_prompt: 'คุณคือผู้ช่วย AI สำหรับ E2E',
  temperature: 0.7,
  max_tokens: 2048,
  knowledge_bases: [],
  is_default: true,
  created_at: '2026-01-01T00:00:00.000000Z',
  updated_at: '2026-01-01T00:00:00.000000Z',
} satisfies Flow
