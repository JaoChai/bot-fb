# API Surface Map — bot-fb

**Date:** 2026-04-16  
**Stack:** Laravel 12 (PHP) Backend + React 19 Frontend  
**Database:** PostgreSQL (Neon) + pgvector  
**Real-time:** Reverb (WebSocket)

---

## 1. Public HTTP API (REST)

### Authentication Endpoints

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| POST | `/api/auth/register` | AuthController@register | No | User registration |
| POST | `/api/auth/login` | AuthController@login | No | User login + token generation |
| GET | `/api/auth/user` | AuthController@user | **Yes** | Get current user profile |
| POST | `/api/auth/logout` | AuthController@logout | **Yes** | Logout current device |
| POST | `/api/auth/logout-all` | AuthController@logoutAll | **Yes** | Logout all devices |
| POST | `/api/auth/refresh` | AuthController@refresh | **Yes** | Refresh auth token (Sanctum) |
| GET | `/api/auth/tokens` | AuthController@tokens | **Yes** | List all issued tokens |
| DELETE | `/api/auth/tokens/{tokenId}` | AuthController@revokeToken | **Yes** | Revoke specific token |

**Auth:** Sanctum token-based (Bearer + localStorage). Cookie-based (withCredentials) also supported.

---

### Bot Management

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/bots` | BotController@index | **Yes** | List all user's bots |
| POST | `/api/bots` | BotController@store | **Yes** | Create new bot |
| GET | `/api/bots/{bot}` | BotController@show | **Yes** | Get bot details |
| PUT | `/api/bots/{bot}` | BotController@update | **Yes** | Update bot config (name, description, LLM settings) |
| DELETE | `/api/bots/{bot}` | BotController@destroy | **Yes** | Delete bot |
| GET | `/api/bots/{bot}/webhook-url` | BotController@webhookUrl | **Yes** | Get current webhook URL |
| POST | `/api/bots/{bot}/regenerate-webhook` | BotController@regenerateWebhook | **Yes** | Regenerate webhook token (breaks old webhook) |
| POST | `/api/bots/{bot}/test` | BotController@test | **Yes** | Test bot with sample message (throttled) |
| POST | `/api/bots/{bot}/test-line` | BotController@testLineConnection | **Yes** | Test LINE channel credentials |
| POST | `/api/bots/{bot}/test-telegram` | BotController@testTelegramConnection | **Yes** | Test Telegram channel credentials |
| GET | `/api/bots/{bot}/credentials` | BotController@credentials | **Yes** | Reveal channel secrets (owner only) |

**Resources:** BotResource (includes settings, default flow, KB reference)

---

### Flow Management

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/bots/{bot}/flows` | FlowController@index | **Yes** | List all flows for a bot |
| POST | `/api/bots/{bot}/flows` | FlowController@store | **Yes** | Create new flow (system prompt, temperature, KB, tools) |
| GET | `/api/bots/{bot}/flows/{flow}` | FlowController@show | **Yes** | Get flow details with full config |
| PUT | `/api/bots/{bot}/flows/{flow}` | FlowController@update | **Yes** | Update flow settings (cached, 30min TTL) |
| DELETE | `/api/bots/{bot}/flows/{flow}` | FlowController@destroy | **Yes** | Delete flow |
| POST | `/api/bots/{bot}/flows/{flow}/set-default` | FlowController@setDefault | **Yes** | Mark flow as default for bot |
| POST | `/api/bots/{bot}/flows/{flow}/duplicate` | FlowController@duplicate | **Yes** | Clone flow with new name |
| POST | `/api/bots/{bot}/flows/{flow}/test` | FlowController@test | **Yes** | Test flow with Chat Emulator (SSE streaming) |
| GET | `/api/flow-templates` | FlowController@templates | **Yes** | Get available flow templates |

**Request:** StoreFlowRequest / UpdateFlowRequest (validate system_prompt ≤50k chars, temperature 0-2, tools whitelist)  
**Resources:** FlowResource, FlowListResource

---

### Flow Plugins

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/bots/{bot}/flows/{flow}/plugins` | FlowPluginController@index | **Yes** | List plugins for a flow |
| POST | `/api/bots/{bot}/flows/{flow}/plugins` | FlowPluginController@store | **Yes** | Add plugin to flow (search_kb, calculate, escalate_to_human, etc.) |
| PUT | `/api/bots/{bot}/flows/{flow}/plugins/{plugin}` | FlowPluginController@update | **Yes** | Update plugin config |
| DELETE | `/api/bots/{bot}/flows/{flow}/plugins/{plugin}` | FlowPluginController@destroy | **Yes** | Remove plugin from flow |

**Plugins Available:** search_kb, calculate, think, get_current_datetime, escalate_to_human

---

### Conversations (Chat History)

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/bots/{bot}/conversations` | ConversationController@index | **Yes** | List conversations (paginated, filter by status) |
| GET | `/api/bots/{bot}/conversations/{conversation}` | ConversationController@show | **Yes** | Get conversation details + metadata |
| PUT | `/api/bots/{bot}/conversations/{conversation}` | ConversationController@update | **Yes** | Update conversation (tags, memory_notes, VIP status) |
| POST | `/api/bots/{bot}/conversations/{conversation}/close` | ConversationController@close | **Yes** | Mark conversation as closed/resolved |
| POST | `/api/bots/{bot}/conversations/{conversation}/reopen` | ConversationController@reopen | **Yes** | Reopen closed conversation |
| POST | `/api/bots/{bot}/conversations/{conversation}/clear-context` | ConversationController@clearContext | **Yes** | Clear conversation context/history |
| POST | `/api/bots/{bot}/conversations/clear-context-all` | ConversationController@clearContextAll | **Yes** | Clear all conversations' context (throttled) |
| GET | `/api/bots/{bot}/conversations/stats` | ConversationController@stats | **Yes** | Get conversation metrics (total, open, by status) |

---

### Conversation Messages

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/bots/{bot}/conversations/{conversation}/messages` | ConversationMessageController@index | **Yes** | Get message history (paginated) |
| POST | `/api/bots/{bot}/conversations/{conversation}/agent-message` | ConversationMessageController@store | **Yes** | Send user message → get AI response (throttled: 60/min) |
| POST | `/api/bots/{bot}/conversations/{conversation}/upload` | ConversationMessageController@upload | **Yes** | Upload image/file for vision tasks (30/min) |
| POST | `/api/bots/{bot}/conversations/{conversation}/mark-as-read` | ConversationMessageController@markAsRead | **Yes** | Mark messages as read |

**Resources:** MessageResource (model_used, cost, token counts, cached_tokens, reasoning_tokens)

---

### Conversation Notes

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/bots/{bot}/conversations/{conversation}/notes` | ConversationNoteController@index | **Yes** | List internal notes on conversation |
| POST | `/api/bots/{bot}/conversations/{conversation}/notes` | ConversationNoteController@store | **Yes** | Add new note |
| PUT | `/api/bots/{bot}/conversations/{conversation}/notes/{noteId}` | ConversationNoteController@update | **Yes** | Edit note |
| DELETE | `/api/bots/{bot}/conversations/{conversation}/notes/{noteId}` | ConversationNoteController@destroy | **Yes** | Delete note |

---

### Conversation Tags & Assignment

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/bots/{bot}/conversations/tags` | ConversationTagController@index | **Yes** | Get all tags used on bot's conversations |
| POST | `/api/bots/{bot}/conversations/{conversation}/tags` | ConversationTagController@store | **Yes** | Add tag to conversation |
| DELETE | `/api/bots/{bot}/conversations/{conversation}/tags/{tag}` | ConversationTagController@destroy | **Yes** | Remove tag from conversation |
| POST | `/api/bots/{bot}/conversations/bulk-tags` | ConversationTagController@bulkStore | **Yes** | Bulk assign tags to multiple conversations |
| POST | `/api/bots/{bot}/conversations/{conversation}/toggle-handover` | ConversationAssignmentController@toggleHandover | **Yes** | Toggle handover mode (agent takes over) |
| POST | `/api/bots/{bot}/conversations/{conversation}/assign` | ConversationAssignmentController@assign | **Yes** | Assign conversation to specific user |
| POST | `/api/bots/{bot}/conversations/{conversation}/claim` | ConversationAssignmentController@claim | **Yes** | Self-claim conversation |
| POST | `/api/bots/{bot}/conversations/{conversation}/unassign` | ConversationAssignmentController@unassign | **Yes** | Unassign conversation |

---

### Knowledge Base (RAG)

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/knowledge-bases` | KnowledgeBaseController@index | **Yes** | List all user's KBs |
| POST | `/api/knowledge-bases` | KnowledgeBaseController@store | **Yes** | Create new KB |
| GET | `/api/knowledge-bases/{kb}` | KnowledgeBaseController@show | **Yes** | Get KB details + doc count |
| PUT | `/api/knowledge-bases/{kb}` | KnowledgeBaseController@update | **Yes** | Update KB name/description |
| DELETE | `/api/knowledge-bases/{kb}` | KnowledgeBaseController@destroy | **Yes** | Delete KB (soft delete docs) |
| POST | `/api/knowledge-bases/{kb}/search` | KnowledgeBaseController@search | **Yes** | Search KB with hybrid (semantic + keyword) |

**Resources:** KnowledgeBaseResource

---

### Knowledge Base Documents

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/knowledge-bases/{kb}/documents` | DocumentController@index | **Yes** | List documents in KB (with status) |
| POST | `/api/knowledge-bases/{kb}/documents` | DocumentController@store | **Yes** | Upload document (PDF, DOC, TXT, etc.; throttled) |
| GET | `/api/knowledge-bases/{kb}/documents/{document}` | DocumentController@show | **Yes** | Get document details + chunks |
| POST | `/api/knowledge-bases/{kb}/documents/{document}/reprocess` | DocumentController@reprocess | **Yes** | Reprocess document (re-embed) |
| DELETE | `/api/knowledge-bases/{kb}/documents/{document}` | DocumentController@destroy | **Yes** | Delete document |

**Request:** StoreDocumentRequest (validate file size ≤50MB, MIME type)  
**Resources:** DocumentResource

---

### Bot Settings

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/bots/{bot}/settings` | BotSettingController@show | **Yes** | Get bot settings (LLM, hours, limits, HITL, aggregation) |
| PUT | `/api/bots/{bot}/settings` | BotSettingController@update | **Yes** | Update bot settings |
| PATCH | `/api/bots/{bot}/settings` | BotSettingController@update | **Yes** | Partial update (same as PUT) |

---

### User Settings

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/settings` | UserSettingController@show | **Yes** | Get user's API keys (OpenRouter, LINE) |
| PUT | `/api/settings/openrouter` | UserSettingController@updateOpenRouter | **Yes** | Update OpenRouter API key |
| PUT | `/api/settings/line` | UserSettingController@updateLine | **Yes** | Update LINE Channel credentials |
| POST | `/api/settings/test-openrouter` | UserSettingController@testOpenRouter | **Yes** | Test OpenRouter key validity |
| POST | `/api/settings/test-line` | UserSettingController@testLine | **Yes** | Test LINE credentials |
| DELETE | `/api/settings/openrouter` | UserSettingController@clearOpenRouter | **Yes** | Clear OpenRouter key |
| DELETE | `/api/settings/line` | UserSettingController@clearLine | **Yes** | Clear LINE credentials |

---

### Quick Replies

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/quick-replies` | QuickReplyController@index | **Yes** | List quick replies |
| GET | `/api/quick-replies/search` | QuickReplyController@search | **Yes** | Search quick replies by keyword |
| POST | `/api/quick-replies` | QuickReplyController@store | **Yes** | Create quick reply |
| GET | `/api/quick-replies/{quick_reply}` | QuickReplyController@show | **Yes** | Get quick reply details |
| PUT | `/api/quick-replies/{quick_reply}` | QuickReplyController@update | **Yes** | Update quick reply |
| DELETE | `/api/quick-replies/{quick_reply}` | QuickReplyController@destroy | **Yes** | Delete quick reply |
| POST | `/api/quick-replies/{quick_reply}/toggle` | QuickReplyController@toggle | **Yes** | Enable/disable quick reply |
| POST | `/api/quick-replies/reorder` | QuickReplyController@reorder | **Yes** | Reorder quick replies |

**Resources:** QuickReplyResource

---

### Orders & Products

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/orders` | OrderController@index | **Yes** | List orders (paginated, filter by status) |
| GET | `/api/orders/summary` | OrderController@summary | **Yes** | Get order summary (total, by status, revenue) |
| GET | `/api/orders/by-customer` | OrderController@byCustomer | **Yes** | Orders grouped by customer |
| GET | `/api/orders/by-product` | OrderController@byProduct | **Yes** | Orders grouped by product |
| GET | `/api/orders/{order}` | OrderController@show | **Yes** | Get order details with items |
| PUT | `/api/orders/{order}` | OrderController@update | **Yes** | Update order (status, notes) |
| GET | `/api/product-stocks` | ProductStockController@index | **Yes** | List product stocks |
| PUT | `/api/product-stocks/{slug}` | ProductStockController@update | **Yes** | Update product stock level |

---

### Dashboard & Analytics

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/dashboard/summary` | DashboardController@summary | **Yes** | Dashboard overview (conversations, costs, orders) |
| GET | `/api/analytics/costs` | AnalyticsController@costs | **Yes** | Token usage + cost breakdown by model |
| GET | `/api/analytics/cache` | AnalyticsController@cacheStats | **Yes** | Cache hit/miss statistics |
| DELETE | `/api/analytics/cache` | AnalyticsController@clearCache | **Yes** | Clear all caches (Redis, semantic) |

---

### Admin & Permissions

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/bots/{bot}/admins` | AdminController@index | **Yes** | List bot admins (owner only) |
| POST | `/api/bots/{bot}/admins` | AdminController@store | **Yes** | Add admin to bot (owner only) |
| DELETE | `/api/bots/{bot}/admins/{user}` | AdminController@destroy | **Yes** | Remove admin from bot (owner only) |
| GET | `/api/users/search` | UserSearchController@search | **Yes** | Search users by email (owner only) |

---

### Agent Approval (HITL)

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/agent-approvals/{approvalId}` | AgentApprovalController@show | **Yes** | Get pending approval details |
| POST | `/api/agent-approvals/{approvalId}/approve` | AgentApprovalController@approve | **Yes** | Approve agent action (human-in-the-loop) |
| POST | `/api/agent-approvals/{approvalId}/reject` | AgentApprovalController@reject | **Yes** | Reject agent action with reason |

---

### Models & Discovery

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/models` | ModelController@index | **Yes** | List all available LLM models (from config/llm-models.php) |

---

### Lead Recovery

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/bots/{bot}/lead-recovery/stats` | LeadRecoveryController@getStats | **Yes** | Lead recovery metrics |
| GET | `/api/bots/{bot}/lead-recovery/logs` | LeadRecoveryController@getLogs | **Yes** | Lead recovery event logs |

---

### Health Check

| Method | Path | Controller | Auth | Purpose |
|--------|------|-----------|------|---------|
| GET | `/api/health` | HealthController@index | No | Basic health check |
| GET | `/api/health/detailed` | HealthController@detailed | **Yes** | Detailed health (DB, cache, queues) |

---

## 2. Webhook Endpoints (Inbound Integrations)

### LINE Webhook
- **Endpoint:** `POST /api/webhook/{token}`
- **Signature Verification:** X-Line-Signature header (HMAC-SHA256)
- **Channel Secret:** Required (stored in bot.channel_secret)
- **Event Types Handled:** message, follow, unfollow, join, leave, postback, beacon
- **Job Queue:** `webhooks` (async, ProcessLINEWebhook job)
- **Response:** Always 200 OK immediately (LINE requires fast response)

### Telegram Webhook
- **Endpoint:** `POST /api/webhook/telegram/{token}`
- **Secret Token:** Optional X-Telegram-Bot-Api-Secret-Token header (if configured)
- **Update Types Handled:** message, edited_message, channel_post, callback_query, inline_query, my_chat_member, chat_member
- **Job Queue:** `webhooks` (async, ProcessTelegramWebhook job)
- **Response:** Always 200 OK with {"ok": true} (Telegram requires fast response)

### Facebook Webhook
- **Endpoint:** `GET /api/webhook/facebook/{token}` (verification)
- **Endpoint:** `POST /api/webhook/facebook/{token}` (events)
- **Job Queue:** `webhooks` (ProcessFacebookWebhook job)

### Webhook Rate Limiting
- **Rate Limit:** `throttle.webhook` middleware (default: 600 req/min per IP)
- **No Auth Required:** Public endpoints (validated by token matching bot.webhook_url)

---

## 3. WebSocket / Real-time (Reverb)

### Broadcasting Channels

| Channel | Auth | Purpose |
|---------|------|---------|
| `conversation.{conversationId}` | Bot owner OR assigned agent | Live message events for specific conversation |
| `bot.{botId}` | Bot owner only | All conversations updates for bot |
| `bot.{botId}.presence` | Bot owner only | User presence tracking (who's online) |
| `user.{userId}.notifications` | Own user only | Personal notifications (approvals, alerts) |
| `knowledge-base.{knowledgeBaseId}` | KB owner only | Document processing status updates |

### Events Broadcasted

- **MessageSent** → `conversation.{conversationId}` (new AI/user message)
- **ConversationUpdated** → `bot.{botId}` (status, tags, assignment)
- **BotSettingsUpdated** → `bot.{botId}` (config change)
- **DocumentStatusUpdated** → `knowledge-base.{knowledgeBaseId}` (processing, embedded)

### Frontend Subscription (from frontend/src/lib/echo.ts)

```typescript
// Example: Subscribe to conversation updates
echo.private(`conversation.${conversationId}`)
  .listen('MessageSent', (event) => { /* handle new message */ })
  .listen('ConversationUpdated', (event) => { /* refresh metadata */ });

// Presence tracking
echo.join(`bot.${botId}.presence`)
  .here((users) => { /* list of online users */ })
  .joining((user) => { /* user came online */ })
  .leaving((user) => { /* user went offline */ });
```

---

## 4. Frontend API Client (src/lib/api.ts)

### Configuration

```typescript
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  withCredentials: true, // Sanctum cookie support
});
```

### Request Interceptor
- Attaches Bearer token from localStorage (`auth_token`)
- Adds X-Socket-ID header for Reverb's toOthers() broadcasting

### Response Interceptor
- Handles 401 → clears token + emits `auth:logout` event
- Transforms errors to ApiError shape: { message, errors, status }

### Helper Functions

```typescript
apiGet<T>(url: string) → Promise<T>
apiPost<T>(url: string, data?: unknown) → Promise<T>
apiPut<T>(url: string, data?: unknown) → Promise<T>
apiDelete<T>(url: string) → Promise<T>

// Agent approval
approveAgentAction(approvalId: string, reason?: string)
rejectAgentAction(approvalId: string, reason?: string)

// Error handling
getErrorMessage(error: unknown) → string
```

---

## 5. API Request/Response Shapes

### LoginRequest Validation

```php
[
  'email' => 'required|string|email',
  'password' => 'required|string',
  'device_name' => 'nullable|string|max:255',
  'revoke_previous' => 'nullable|boolean',
]
// Response: { 'access_token', 'token_type', 'expires_in' }
```

### StoreFlowRequest Validation

```php
[
  'name' => 'required|string|max:255',
  'description' => 'nullable|string|max:1000',
  'system_prompt' => 'required|string|max:50000',
  'temperature' => 'nullable|numeric|between:0,2',
  'max_tokens' => 'nullable|integer|min:1|max:128000',
  'agentic_mode' => 'nullable|boolean',
  'max_tool_calls' => 'nullable|integer|min:1|max:20',
  'enabled_tools' => 'nullable|array', // [search_kb, calculate, think, ...]
  'knowledge_bases' => 'nullable|array',  // with kb_top_k, kb_similarity_threshold
  'language' => 'nullable|string|max:10',
  'is_default' => 'nullable|boolean',
  'second_ai_enabled' => 'nullable|boolean',
  'second_ai_options' => 'nullable|array', // fact_check, policy, personality
  'agent_timeout_seconds' => 'nullable|integer|min:30|max:300',
  'agent_max_cost_per_request' => 'nullable|numeric|min:0.01|max:10',
  'hitl_enabled' => 'nullable|boolean',
  'hitl_dangerous_actions' => 'nullable|array',
]
```

### BotResource Response

```json
{
  "id": 1,
  "name": "Customer Support Bot",
  "channel_type": "line|telegram|facebook",
  "webhook_url": "https://api.example.com/api/webhook/xxx",
  "channel_access_token": "••••••••",  // masked
  "credentials_visible": true,  // owner only
  "primary_chat_model": "openai/gpt-4o",
  "fallback_chat_model": "openai/gpt-4o-mini",
  "kb_enabled": true,
  "kb_relevance_threshold": 0.7,
  "kb_max_results": 3,
  "use_confidence_cascade": false,
  "cascade_cheap_model": "openai/gpt-4o-mini",
  "cascade_expensive_model": "openai/gpt-4o",
  "total_conversations": 42,
  "total_messages": 512,
  "created_at": "2024-01-15T10:30:00Z",
  "settings": { /* BotSetting */ },
  "default_flow": { /* FlowResource */ }
}
```

### MessageResource Response

```json
{
  "id": 123,
  "conversation_id": 45,
  "sender": "user|bot",
  "content": "What is your return policy?",
  "type": "text|image|sticker|location",
  "media_url": "https://...",
  "media_type": "image/jpeg",
  "model_used": "openai/gpt-4o",
  "prompt_tokens": 250,
  "completion_tokens": 45,
  "cost": 0.0035,
  "cached_tokens": 100,  // prompt cache hit
  "reasoning_tokens": 0,
  "sentiment": "neutral",
  "intents": ["product_inquiry"],
  "created_at": "2024-04-16T08:30:00Z"
}
```

---

## 6. Async Jobs (Queue-based Processing)

| Job | Queue | Timeout | Purpose |
|-----|-------|---------|---------|
| ProcessLINEWebhook | webhooks | 60s | Handle incoming LINE messages, convert to conversations |
| ProcessTelegramWebhook | webhooks | 60s | Handle Telegram updates |
| ProcessFacebookWebhook | webhooks | 60s | Handle Facebook messages |
| ProcessDocument | documents | 300s | Extract text, chunk, embed, index (RAG) |
| ExtractEntitiesJob | default | 30s | NER + intent extraction from conversation |
| ProcessLeadRecovery | default | 120s | Lead recovery pipeline (inactive user re-engagement) |
| ProcessAggregatedMessages | default | 30s | Message aggregation (batch multiple msgs) |
| SendDelayedBubbleJob | default | 60s | Send LINE rich bubbles/flex messages with delay |

### Job Dispatch Pattern

```php
// Sync (wait for response)
$result = Mail::send(...);

// Async (queue it)
SomeJob::dispatch($data)->onQueue('webhooks');

// With delay
SomeJob::dispatch($data)->delay(now()->addMinutes(5));

// Priority
SomeJob::dispatch($data)->onQueue('high-priority');
```

---

## 7. External Integrations (Outbound)

### OpenRouter API (LLM)
- **Base URL:** `https://openrouter.ai/api/v1`
- **Service:** OpenRouterService
- **Methods:** chat() (streaming + non-streaming), list models, get usage stats
- **Auth:** API key from user settings or env (OPENROUTER_API_KEY)
- **Features:** Native fallback (2-model cascade), tool use, reasoning models (o1, deepseek-r1), vision, structured output (JSON mode)
- **Cost Tracking:** Prompt tokens, completion tokens, cached tokens, reasoning tokens

### LINE Messaging API
- **Base URL:** `https://api.line.me/v2`
- **Service:** LINEService
- **Methods:** Send reply (replyToken), send push (userId), validate signature
- **Auth:** Channel access token + channel secret
- **Event Types:** message (text, image, sticker, location, video), follow, unfollow, postback

### Telegram Bot API
- **Base URL:** `https://api.telegram.org/bot{token}`
- **Service:** TelegramService
- **Methods:** sendMessage, editMessage, setWebhook, deleteWebhook, getMe
- **Auth:** Bot token (from user settings)
- **Secret Token:** Optional X-Telegram-Bot-Api-Secret-Token

### PostgreSQL (Neon)
- **Vector Embeddings:** pgvector extension (for semantic search)
- **Full-Text Search:** GIN indexes on document_chunks.content
- **Operations:** Hybrid search (semantic + keyword), semantic cache (RRF fusion)

### Embedding Service
- **Provider:** OpenRouter (from config) or custom
- **Models:** Jina embeddings, OpenAI embeddings
- **Dimension:** 1024 (depends on model)

### Reranking (Optional Phase 2)
- **Providers:** Jina AI, Cohere
- **Purpose:** Re-score retrieval results for better accuracy

### Vision Models
- **Supported Models:** GPT-4o, GPT-4o-mini, Claude 3.5 Sonnet, Gemini 2.0 Vision
- **Use Cases:** Image analysis, document OCR, product photos
- **Validation:** ModelCapabilityService checks supportsVision flag

---

## 8. Events & Listeners

| Event | Broadcaster | Listeners |
|-------|-------------|-----------|
| MessageSent | conversation.{conversationId} | Frontend: update chat UI, show cost |
| ConversationUpdated | bot.{botId} | Frontend: refresh conversation metadata |
| BotSettingsUpdated | bot.{botId} | Frontend: reload settings cache |
| DocumentStatusUpdated | knowledge-base.{knowledgeBaseId} | Frontend: show processing progress |

---

## 9. Configuration-Driven Behavior

### llm-models.php
- ~33 models with metadata (context_length, pricing, supports_vision, supports_reasoning)
- Capability flags: supportsVision, supportsReasoning, isMandatoryReasoning, supportsStructuredOutput
- Used by ModelCapabilityService to validate model selections

### rag.php
- Retrieval strategy: hybrid search (semantic + keyword, RRF fusion)
- Reranking: optional (Jina AI or Cohere)
- Query enhancement: optional query expansion via LLM
- Semantic cache: pgvector-based exact + similarity matching (92% threshold)
- Contextual retrieval: auto-context generation for chunks (Anthropic technique)
- Corrective RAG (CRAG): evaluate retrieval quality, rewrite queries if ambiguous
- Chain-of-Thought: auto-detect complex questions, add reasoning prompt

### agent-prompts.php (Reference)
- System prompts for different agent modes (Thai/English)
- Tool templates for Knowledge Base search, calculations
- Safety guardrails (max token limits, cost caps)

---

## 10. Extension Points (Plugin/Config Architecture)

### Service Bindings
- Custom LLM provider: extend OpenRouterService
- Custom embedding: extend EmbeddingService
- Custom webhook handler: add to Webhook\*Controller

### Event Listeners
- Subscribe to MessageSent event for custom logging/analytics
- Subscribe to DocumentStatusUpdated for notifications

### Flow Plugins
- Extensible tool system: search_kb, calculate, think, escalate_to_human
- Add custom tools via Flow plugins UI

### Config Overrides
- Per-bot settings (BotSetting model)
- Per-flow settings (Flow model)
- Per-user settings (UserSetting model)

---

## 11. Rate Limiting Tiers

| Middleware | Limit | Purpose |
|-----------|-------|---------|
| throttle.auth | strict (custom) | Login/register endpoints |
| throttle.api | 60/min | General authenticated endpoints |
| throttle.bot-test | 5/min | Bot test + flow test (expensive) |
| throttle:10,1 | 10/min | LINE/Telegram connection test |
| throttle.webhook | 600/min | Incoming webhooks (per IP) |
| throttle:60,1 | 60/min | Message send (conversations.agent-message) |
| throttle:30,1 | 30/min | File upload (conversations.upload) |
| throttle.uploads | 10/min | KB document upload |

---

## 12. Data Flow Example: User sends message

1. **Frontend:** POST `/api/bots/{bot}/conversations/{conversation}/agent-message`
   - Payload: { content: "What products do you have?" }
   - Header: Authorization: Bearer {token}, X-Socket-ID: {socketId}

2. **Backend:** ConversationMessageController@store
   - Validate request (throttle: 60/min)
   - Load conversation + bot + flow + KB
   - Call RAGService (search KB if enabled)
   - Call OpenRouterService.chat() (with streaming or sync)
   - Create Message record (save tokens, cost)
   - Broadcast MessageSent to conversation.{conversationId}
   - Return MessageResource

3. **Frontend:** Receives response
   - Response interceptor extracts data
   - Zustand chat store updates
   - Echo listener (MessageSent) triggers re-render
   - Show message + cost in UI

---

## 13. Critical Paths

| Path | Critical Service |
|------|------------------|
| Incoming message → Response | RAGService + OpenRouterService + StockGuardService |
| Document upload → Searchable | DocumentParserService + EmbeddingService + ChunkingService |
| Conversation created | Auto-assigned if auto_handover enabled (AutoAssignmentService) |
| Flow updated | Cache invalidated (FlowCacheService) |
| Agent action (HITL) | Approval queued, human review required |

---

## Summary

**Total API Endpoints:** ~80+ REST endpoints  
**Broadcast Channels:** 5 real-time channels  
**Webhook Integrations:** 3 (LINE, Telegram, Facebook)  
**External Services:** OpenRouter (LLM), LINE, Telegram, Postgres (Neon)  
**Async Jobs:** 8 job types  
**Auth:** Laravel Sanctum (token-based + cookie)  
**Rate Limiting:** 7 tiers (context-aware)  
**Config-Driven:** 3 main configs (llm-models, rag, agent-prompts)
