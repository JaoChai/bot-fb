# BotFacebook Architecture Deep Dive

**Date**: 2026-04-16  
**Stack**: Laravel 12 + PHP 8.4 (Backend) | React 19 + TypeScript + Tailwind v4 (Frontend)  
**Database**: PostgreSQL + pgvector (Neon)  
**Real-time**: Reverb WebSocket | **Deploy**: Railway  
**AI**: OpenRouter API | **Payment**: Payment Flow + Flex Message Integration

---

## 1. Directory Structure & Organization Philosophy

### 1.1 Top-Level Layout

```
bot-fb/
├── backend/          # Laravel 12 + PHP 8.4 API
│   ├── app/
│   ├── config/
│   ├── database/     # Migrations, factories, seeders
│   ├── routes/
│   ├── tests/
│   └── bootstrap/
├── frontend/         # React 19 + TypeScript SPA
│   ├── src/
│   ├── public/
│   ├── tests/
│   └── vite.config.ts
└── docs/             # Documentation
```

### 1.2 Backend Directory Breakdown

**`app/Models/` (26 models)**  
Entity models with relationships. Key models:
- **Bot** — Chatbot config (channels, LLM settings, RAG, stock guards, handover config)
- **Flow** — Conversation logic blueprint (system prompt, temperature, tools, agent safety settings)
- **Conversation** — Chat session (status, memory, tags, assignment, recovery tracking)
- **Message** — Individual messages in a conversation
- **KnowledgeBase** — Document collection for RAG
- **Document + DocumentChunk** — RAG knowledge (chunked & embedded)
- **Order + OrderItem + ProductStock** — E-commerce data
- **User + BotSetting + UserSetting** — Multi-tenant auth
- **Flow related**: QuickReply, FlowPlugin, RagCache, SemanticCache models

**`app/Services/` (46 services)**  
Business logic organized by domain:

| Category | Services |
|----------|----------|
| **LLM/AI** | OpenRouterService (API wrapper), RAGService (orchestrator), AIService, ModelCapabilityService |
| **Search/Retrieval** | SemanticSearchService, HybridSearchService, KeywordSearchService, ContextualRetrievalService, JinaRerankerService |
| **RAG Pipeline** | EmbeddingService, ChunkingService, DocumentParserService, QueryEnhancementService, CRAGService (chain-of-RAG) |
| **Caching** | FlowCacheService, ConversationCacheService, CacheService, SemanticCacheService (prompt-response cache) |
| **Messaging** | LINEService, TelegramService, FacebookService (channel APIs) |
| **Stock Management** | StockGuardService (post-gen validation), StockInjectionService (prompt injection + guard logic) |
| **Orders** | OrderService, PaymentFlexService (payment flow detection) |
| **Safety/Control** | AgentSafetyService, CircuitBreakerService, RateLimitService, IntentAnalysisService |
| **Features** | LeadRecoveryService, ResponseHoursService, MessageAggregationService, ProfilePictureService, StickerReplyService, MultipleBubblesService |
| **Analytics** | CostTrackingService, ResilienceMetricsService |

**`app/Jobs/` (8 async jobs, queued by Laravel Queue)**
- ProcessLINEWebhook, ProcessTelegramWebhook, ProcessFacebookWebhook
- ProcessDocument (chunking + embedding)
- ProcessAggregatedMessages (batch processing)
- ExtractEntitiesJob (NER)
- ProcessLeadRecovery (re-engagement)
- SendDelayedBubbleJob (scheduled messages)

**`app/Http/Controllers/Api/` (~20+ controllers)**  
RESTful endpoints grouped by resource:
- AuthController (register, login, logout, token management)
- BotController (CRUD, test, credentials, webhook)
- FlowController (CRUD, test emulator, duplication)
- ConversationController (list, show, update, clear-context, handover)
- ConversationMessageController (send, upload, history)
- KnowledgeBaseController (CRUD documents, search)
- OrderController, ProductStockController (e-commerce)
- DashboardController (stats), AnalyticsController (costs)

**`config/` (Key configuration)**
- **llm-models.php** — 33+ LLM models with pricing, context length, vision support, reasoning flags
- **rag.php** — RAG settings (hybrid search weights, cache TTL, stock guard thresholds)
- **agent-prompts.php** — System prompt templates (Thai/English, intent-based routing)
- **tools.php** — Tool definitions for agentic mode
- **webhooks.php** — Webhook secret validation

**`database/migrations/` (100+ migrations)**  
Schema evolution tracked chronologically. Key tables:
- `bots` — Core bot config + LLM/RAG/routing settings
- `flows` — Flow templates with safety constraints
- `conversations` — Active chat sessions with memory
- `messages` — Message history with metadata
- `document_chunks` — RAG vectors + pgvector embeddings
- `orders`, `order_items`, `product_stocks` — E-commerce
- `agent_cost_usage` — Token cost tracking
- `injection_attempts_log`, `second_ai_logs` — Security audit

### 1.3 Frontend Directory Breakdown

**`src/components/` (13+ subdirectories)**
- **chat/** — Message bubble, input, composer, typing indicator
- **conversation/** — Conversation list, detail view, sidebar
- **flow/** — Flow builder, node editor, connector UI
- **flows/** — Flow management (CRUD, templates)
- **knowledge-base/** — Document upload, chunking UI
- **dashboard/** — Stats cards, charts (revenue, conversation trends)
- **layout/** — Header, sidebar, page wrapper
- **ui/** — Radix UI primitives (button, dialog, form, select, table, tabs)
- **telegram/, line/, auth/** — Channel-specific + auth forms

**`src/pages/` (12 main pages)**
- `/` — Dashboard (conversation overview)
- `/bots` — Bot list & CRUD
- `/bots/:botId/flows` — Flow management
- `/bots/:botId/conversations` — Conversation history
- `/bots/:botId/settings` — Bot connection & RAG config
- `/knowledge-bases` — Document management
- `/orders` — E-commerce orders view
- `/settings` — User API key + preferences
- `/auth/login`, `/auth/register` — Auth pages

**`src/hooks/` (25+ custom hooks)**
- **useBotQuery**, **useConversationQuery** — TanStack Query (React Query) data fetching
- **useChat** — Message sending, loading state
- **useFormState** — Form handling with Zod validation
- **useWebSocket** — Reverb connection + real-time updates
- **useCostTracking** — Cost analytics

**`src/stores/` (Zustand state management)**
- **authStore** — User + token + permissions
- **chatStore** — Current conversation + messages
- **uiStore** — Sidebar toggle, modal visibility
- **botPreferencesStore** — Bot selection + view mode
- **connectionStore** — WebSocket readiness

**`src/lib/` (Utilities)**
- **api.ts** — Axios client with interceptors (auth token, socket ID for Reverb)
- **query.ts** — TanStack Query config + React Query Persist
- **echo.ts** — Reverb/Laravel Echo websocket initialization
- **validation.ts** — Zod schemas

---

## 2. Entry Points (All of Them)

### 2.1 HTTP API Entry Points (`routes/api.php`)

**Route Structure**: `/api/*` (all routes prefixed with `/api`)

#### Auth (Public, Rate Limited)
```
POST   /api/auth/register          — User sign-up
POST   /api/auth/login             — User authentication
```

#### Auth (Protected)
```
GET    /api/auth/user              — Current user profile
POST   /api/auth/logout            — Single session logout
POST   /api/auth/logout-all        — Revoke all tokens
GET    /api/auth/tokens            — List active tokens
DELETE /api/auth/tokens/{id}       — Revoke specific token
```

#### Bots (Nested Resource)
```
GET    /api/bots                   — List user's bots
POST   /api/bots                   — Create bot
GET    /api/bots/{id}              — Get bot details
PUT    /api/bots/{id}              — Update bot config
DELETE /api/bots/{id}              — Delete bot

POST   /api/bots/{id}/test         — Test flow (emulator)
POST   /api/bots/{id}/test-line    — Test LINE connection
POST   /api/bots/{id}/test-telegram — Test Telegram connection
GET    /api/bots/{id}/credentials  — Reveal encrypted tokens (owner only)
GET    /api/bots/{id}/webhook-url  — Get webhook URL
POST   /api/bots/{id}/regenerate-webhook — Rotate webhook secret
```

#### Flows (Nested under Bot)
```
GET    /api/bots/{botId}/flows     — List flows for bot
POST   /api/bots/{botId}/flows     — Create flow
GET    /api/bots/{botId}/flows/{id} — Get flow details
PUT    /api/bots/{botId}/flows/{id} — Update flow
DELETE /api/bots/{botId}/flows/{id} — Delete flow

POST   /api/bots/{botId}/flows/{id}/test — Test single flow
POST   /api/bots/{botId}/flows/{id}/set-default — Make default
POST   /api/bots/{botId}/flows/{id}/duplicate — Clone flow

GET    /api/flow-templates         — Available templates
```

#### Flow Plugins (Nested under Flow)
```
GET    /api/bots/{botId}/flows/{flowId}/plugins
POST   /api/bots/{botId}/flows/{flowId}/plugins
PUT    /api/bots/{botId}/flows/{flowId}/plugins/{id}
DELETE /api/bots/{botId}/flows/{flowId}/plugins/{id}
```

#### Conversations (Nested under Bot)
```
GET    /api/bots/{botId}/conversations — List conversations
GET    /api/bots/{botId}/conversations/{id} — Get conversation
PUT    /api/bots/{botId}/conversations/{id} — Update (notes, tags, memory)
POST   /api/bots/{botId}/conversations/{id}/close — Mark closed
POST   /api/bots/{botId}/conversations/{id}/reopen — Reactivate

GET    /api/bots/{botId}/conversations/stats — Aggregates (total, unread)
POST   /api/bots/{botId}/conversations/{id}/clear-context — Reset memory
POST   /api/bots/{botId}/conversations/clear-context-all — Bulk clear
```

#### Messages (Nested under Conversation)
```
GET    /api/bots/{botId}/conversations/{convId}/messages — History
POST   /api/bots/{botId}/conversations/{convId}/agent-message — Send (triggers RAGService)
POST   /api/bots/{botId}/conversations/{convId}/upload — Media upload (triggers ProcessDocument)
POST   /api/bots/{botId}/conversations/{convId}/mark-as-read — Mark read
```

#### Knowledge Bases (Standalone)
```
GET    /api/knowledge-bases        — List all KBs (user's account)
POST   /api/knowledge-bases        — Create KB
GET    /api/knowledge-bases/{id}   — Get KB details
PUT    /api/knowledge-bases/{id}   — Update KB
DELETE /api/knowledge-bases/{id}   — Delete KB

POST   /api/knowledge-bases/{id}/search — Semantic search
```

#### Documents (Nested under KB)
```
GET    /api/knowledge-bases/{kbId}/documents — List documents
POST   /api/knowledge-bases/{kbId}/documents — Upload & enqueue ProcessDocument
GET    /api/knowledge-bases/{kbId}/documents/{docId} — Get document
POST   /api/knowledge-bases/{kbId}/documents/{docId}/reprocess — Re-chunk & embed
DELETE /api/knowledge-bases/{kbId}/documents/{docId} — Delete & clear vectors
```

#### Orders
```
GET    /api/orders                 — List all orders
GET    /api/orders/summary         — Stats (total, revenue)
GET    /api/orders/by-customer     — Grouped by customer
GET    /api/orders/by-product      — Grouped by product
GET    /api/orders/{id}            — Order details
PUT    /api/orders/{id}            — Update order status
```

#### Product Stock
```
GET    /api/product-stocks         — List all products
PUT    /api/product-stocks/{slug}  — Update stock level (owner only)
```

#### Settings
```
GET    /api/settings               — User API keys + prefs
PUT    /api/settings/openrouter    — Update OpenRouter API key
PUT    /api/settings/line          — Update LINE channel secret
POST   /api/settings/test-openrouter — Validate OpenRouter key
DELETE /api/settings/openrouter    — Remove OpenRouter key
```

#### Dashboard & Analytics
```
GET    /api/dashboard/summary      — User stats (bots, conversations, costs)
GET    /api/analytics/costs        — Cost breakdown by model/bot
GET    /api/analytics/cache        — Cache hit/miss rates
DELETE /api/analytics/cache        — Clear all caches
```

#### Health Check
```
GET    /health                     — Status (no auth)
GET    /health/detailed            — Detailed status (auth required)
```

---

### 2.2 Webhook Entry Points (`routes/api.php`)

**Route Structure**: `/api/webhook/*` (Public, **no auth**)

All webhooks are **queued** — received and immediately enqueued as jobs, then response returns 200.

#### LINE Webhook
```
POST /api/webhook/{token}
```
Handles: text messages, postback, follow/unfollow, join/leave events.  
**Job**: ProcessLINEWebhook → RAGService.generateResponse() → LINEService.send()

#### Telegram Webhook
```
POST /api/webhook/telegram/{token}
```
Handles: text messages, photo, document (for RAG), callback queries.  
**Job**: ProcessTelegramWebhook → RAGService.generateResponse() → TelegramService.send()

#### Facebook Webhook
```
GET  /api/webhook/facebook/{token}  — Verification
POST /api/webhook/facebook/{token}  — Messages & postbacks
```
Handles: Messenger text, postback, quick reply.  
**Job**: ProcessFacebookWebhook → RAGService.generateResponse() → FacebookService.send()

---

### 2.3 Frontend Entry Point

**File**: `src/main.tsx`

```typescript
// Initialize Sentry for error monitoring
// Create React Query client with persistence
// Initialize Reverb WebSocket
// Render Router (React Router v7)
```

**Router** (`src/router.tsx`):
- Public routes: `/login`, `/register`
- Protected routes (require auth):
  - `/` — Dashboard
  - `/bots` — Bot list
  - `/bots/:botId/*` — Bot detail + flows + conversations
  - `/knowledge-bases` — KB management
  - `/orders` — Order tracking
  - `/settings` — User settings

---

### 2.4 Artisan Commands

Key commands (via `php artisan`):
```bash
php artisan migrate              # Run database migrations
php artisan queue:listen         # Start job worker (dev)
php artisan queue:work           # Background worker (prod)
php artisan queue:failed         # View failed jobs
php artisan tinker               # Interactive REPL
php artisan cache:clear          # Clear all caches
php artisan config:cache         # Optimize config loading
```

---

## 3. Core Abstractions & Relationships

### 3.1 RAGService — Main AI Orchestrator

**File**: `app/Services/RAGService.php`

**Responsibility**: Generate bot responses using multi-model architecture.

**Flow**:

```
1. Semantic Cache Check
   ├─ Hit? Return cached response (fastest path)
   └─ Miss? Continue...

2. Intent Analysis (Decision Model)
   └─ Classify user intent (knowledge/order/greeting/etc)

3. Knowledge Base Retrieval (if KB enabled & intent='knowledge')
   ├─ Hybrid Search (vector + keyword, RRF fusion)
   ├─ Optional: Jina Reranking
   └─ Build context from chunks

4. Stock Injection
   ├─ Append stock status to system prompt
   └─ Insert: "⛔ [OUT_OF_STOCK]: X, Y, Z"

5. Generate Response (Chat Model)
   └─ Use RAG context + system prompt

6. Post-Generation Guard (StockGuardService)
   ├─ Validate response mentions out-of-stock products
   └─ Strip upsell or block entire response

7. Cache Response
   ├─ Store in SemanticCache for future queries
   └─ TTL: configurable

8. Return Response
   └─ Content + usage stats + metadata
```

**Key Methods**:
- `generateResponse(Bot, userMessage, conversationHistory, conversation?, flow?)` — Main entry point
- `shouldSkipCache(userMessage, conversation, history)` — Prevent cross-conversation contamination
- `getApiKeyForBot(Bot)` — Resolve OpenRouter key (user override or system default)

**Dependencies Injected** (constructor):
```php
SemanticSearchService $semanticSearch
HybridSearchService $hybridSearch
OpenRouterService $openRouter
IntentAnalysisService $intentAnalysis
FlowCacheService $flowCache
QueryEnhancementService $queryEnhancement (optional)
SemanticCacheService $semanticCache (optional)
ToolService $toolService (optional)
CRAGService $cragService (optional)
StockInjectionService $stockInjection
```

---

### 3.2 OpenRouterService — LLM API Wrapper

**File**: `app/Services/OpenRouterService.php`

**Responsibility**: Send requests to OpenRouter API with fallback model support.

**Key Methods**:
```php
chat(
    array $messages,
    ?string $model,
    ?float $temperature,
    ?int $maxTokens,
    bool $useFallback = true,
    ?string $apiKeyOverride,
    ?string $fallbackModelOverride,
    ?int $timeout,
    ?array $reasoning,           // o1/deepseek-r1 reasoning config
    ?array $responseFormat        // JSON mode for structured output
): array
```

**Features**:
- Native fallback support (OpenRouter `models` array)
- Reasoning config for o1/o1-mini/deepseek-r1/gpt-5-mini
- Structured output via `response_format`
- Timeout + retry logic
- Detailed usage tracking (input/output tokens)

**Configuration** (`config/services.php`):
```php
'openrouter' => [
    'api_key' => env('OPENROUTER_API_KEY'),
    'base_url' => 'https://openrouter.ai/api/v1',
    'default_model' => 'anthropic/claude-3.5-sonnet',
    'fallback_model' => 'openai/gpt-4o-mini',
    'timeout' => 60,
    'max_tokens' => 4096,
]
```

---

### 3.3 FlowCacheService — Flow Config Cache

**File**: `app/Services/FlowCacheService.php`

**Responsibility**: Cache Flow data with 30-minute TTL to reduce DB load.

**Cache Keys**:
```php
"bot:{$botId}:default_flow"     // Default flow model
"bot:{$botId}:has_flows"        // Boolean flag
```

**Methods**:
```php
getDefaultFlow(int $botId): ?Flow
hasFlows(int $botId): bool
invalidateBot(int $botId): void
invalidateDefaultFlow(int $botId): void
```

**When to invalidate**:
- Flow created/updated/deleted → `invalidateBot($botId)`
- Default flow status changes → `invalidateDefaultFlow($botId)`

---

### 3.4 Model Hierarchy & Relationships

```
User (1:M)
├─ Bot (1:M)
│  ├─ Flow (1:M, has_one default) — Contains system prompt, agent settings
│  │  ├─ FlowPlugin (1:M) — Tool/plugin instances
│  │  ├─ FlowKnowledgeBase (M:M) — Links to KBs used by this flow
│  │  └─ KnowledgeBase (M:M) — Searchable document collections
│  │     ├─ Document (1:M) — Individual files (PDF, DOCX, TXT)
│  │     └─ DocumentChunk (1:M) — Chunked text + pgvector embeddings
│  │
│  ├─ Conversation (1:M) — One chat session per customer
│  │  ├─ Message (1:M) — Sequential messages
│  │  ├─ CustomerProfile (M:1) — Linked customer info
│  │  └─ User (1:M, optional) — Assignment (for handover)
│  │
│  ├─ BotSetting (1:1) — Channel config (LINE/Telegram/Facebook keys)
│  ├─ BotLimit (1:1) — Rate limits
│  ├─ BotHITLSettings (1:1) — Human-in-the-loop config
│  ├─ BotAggregationSettings (1:1) — Message batching config
│  └─ BotResponseHours (1:M) — Operating hours per day
│
├─ UserSetting (1:1) — API keys, preferences
├─ AdminBotAssignment (M:M) — Admin access to bots
└─ Order (1:M) — E-commerce orders
   └─ OrderItem (1:M) — Line items in order
```

---

### 3.5 Stock Management — Double Prompt Injection Pattern

**Components**:

1. **StockInjectionService** (prompt-time)
   - Builds stock status string for system prompt
   - Fetches ProductStock (cached, 5-min TTL)
   - Formats as Thai warning: `⛔ [สินค้าที่หมดชั่วคราว]: X, Y`

2. **StockGuardService** (post-gen validation)
   - Receives LLM response
   - Scans for violation keywords: "price", "cart", "order", "add to cart"
   - If found with out-of-stock product:
     - **Upsell only** → Strip upsell block, keep main response
     - **Main product** → Reject entire response with stock-out message

**Gotcha**: (from retro)
- `supportsVision()` check too strict → accept images but don't process
- StockGuard regex can false-positive on "payment" line items
- `memory_notes` sometimes stored as object `{vip:true}` instead of array

---

### 3.6 Service Dependency Graph

```
RAGService (orchestrator)
├─ OpenRouterService (chat API)
├─ SemanticSearchService (vector search, pgvector)
├─ HybridSearchService (semantic + keyword, RRF)
│  ├─ SemanticSearchService
│  └─ KeywordSearchService (full-text)
├─ IntentAnalysisService (decision model)
├─ FlowCacheService (config cache)
├─ QueryEnhancementService (query rewriting)
├─ SemanticCacheService (response cache, optional)
├─ JinaRerankerService (reranking, optional)
├─ StockInjectionService (prompt injection)
├─ StockGuardService (post-gen validation)
│  └─ StockInjectionService
├─ ToolService (agentic tools, optional)
└─ CRAGService (chain-of-RAG, optional)
   ├─ RAGService (recursive)
   └─ OpenRouterService
```

---

## 4. Dependencies

### 4.1 Backend (`composer.json`)

**Core Framework**:
- `laravel/framework` ^12.0 — Web framework, ORM (Eloquent), migrations, queue, cache
- `laravel/sanctum` ^4.0 — API token authentication
- `laravel/reverb` ^1.6 — WebSocket server (real-time updates)
- `laravel/tinker` ^2.10 — REPL for debugging

**Database & Vector**:
- `pgvector/pgvector` ^0.2.2 — PHP binding for PostgreSQL pgvector extension

**AI & Parsing**:
- No built-in AI client (uses OpenRouter via Guzzle/Http)
- `smalot/pdfparser` ^2.12 — PDF text extraction

**Observability**:
- `sentry/sentry-laravel` ^4.20 — Error tracking & performance monitoring

**API Documentation**:
- `darkaonline/l5-swagger` ^10.0 — OpenAPI/Swagger generation
- `doctrine/annotations` ^2.0 — Annotation parser for Swagger

**Storage**:
- `league/flysystem-aws-s3-v3` ^3.0 — S3 file uploads (optional, via Flysystem)

**Dev Dependencies** (testing, linting):
- `pestphp/pest` ^3.8 — Modern PHP testing framework
- `phpunit/phpunit` ^11.5.3 — Unit testing
- `laravel/pint` ^1.24 — Code style linter (PSR-12)
- `laravel/boost` ^2.1 — Development tools

---

### 4.2 Frontend (`package.json`)

**Core**:
- `react` ^19.2.0, `react-dom` ^19.2.0 — UI framework
- `react-router` ^7.11.0 — Client-side routing
- `typescript` ~5.9.3 — Type safety

**Data Fetching & Caching**:
- `@tanstack/react-query` ^5.90 — Server state management (formerly React Query)
- `@tanstack/react-query-persist-client` ^5.90 — Query caching/persistence
- `@tanstack/react-query-devtools` ^5.91 — Debug devtools
- `axios` ^1.13.2 — HTTP client

**Real-time & WebSocket**:
- `laravel-echo` ^2.2.6 — Laravel broadcasting client
- `pusher-js` ^8.4.0 — WebSocket transport (Reverb uses Pusher protocol)

**UI Components & Styling**:
- `@radix-ui/*` — Headless component library (select, dialog, tabs, etc.)
- `tailwindcss` ^4.1.18 — Utility-first CSS
- `tailwind-merge` ^3.4.0 — Merge Tailwind class names
- `class-variance-authority` ^0.7.1 — Component style composition
- `lucide-react` ^0.562.0 — Icon library
- `recharts` ^3.6.0 — Chart library (React component based)

**Forms & Validation**:
- `react-hook-form` ^7.69.0 — Form state & validation
- `@hookform/resolvers` ^5.2.2 — Validation library adapters (Zod, Yup, etc.)
- `zod` ^4.2.1 — Schema validation & TypeScript inference

**State Management**:
- `zustand` ^5.0.9 — Lightweight store (auth, chat, UI state)

**Utilities**:
- `date-fns` ^4.1.0 — Date manipulation
- `clsx` ^2.1.1 — Conditional class names
- `sonner` ^2.0.7 — Toast notifications
- `next-themes` ^0.4.6 — Dark mode switching

**Monitoring**:
- `@sentry/react` ^10.32.1 — Error tracking
- `web-vitals` ^5.1.0 — Core Web Vitals reporting

**Dev Tools**:
- `vite` ^7.2.4 — Build tool (dev server, bundler)
- `@vitejs/plugin-react` ^5.1.1 — React plugin for Vite
- `vitest` ^4.0.18 — Unit testing (Vite-native)
- `@testing-library/react` ^16.3.2 — Component testing
- `eslint` ^9.39.1 — Code linting
- `msw` ^2.12.8 — Mock Service Worker (API mocking for tests)

---

### 4.3 External Services

| Service | Purpose | Integration |
|---------|---------|-------------|
| **OpenRouter API** | LLM inference (40+ models) | HTTP client in OpenRouterService |
| **PostgreSQL + pgvector** | Database + vector embeddings | Eloquent + native pgvector queries |
| **Neon** | Managed PostgreSQL hosting | Connection via DATABASE_URL |
| **Railway** | Application deployment | Push-to-deploy via Git |
| **Reverb** (WebSocket) | Real-time message updates | Broadcast channels via Laravel Echo |
| **LINE Messaging API** | Messaging channel | LINEService HTTP wrapper |
| **Telegram Bot API** | Messaging channel | TelegramService HTTP wrapper |
| **Facebook Messenger** | Messaging channel | FacebookService + Webhooks |
| **Sentry** | Error tracking | SDK integration |

---

## 5. Flow Diagrams (Text-Based)

### 5.1 User → Response Flow

```
Customer sends message on LINE/Telegram/Facebook
         ↓
Webhook endpoint receives (public, queued immediately)
         ↓
ProcessWebhookJob enqueued
         ↓
Job executes (via queue worker)
├─ Extract metadata (customer_id, channel, text)
├─ Create/find Conversation
└─ Enqueue ConversationMessageController.store → RAGService
         ↓
RAGService.generateResponse(bot, userMessage, history)
├─ Check SemanticCache (vector similarity)
├─ If miss:
│  ├─ IntentAnalysisService.analyze() → intent label
│  ├─ If intent='knowledge':
│  │  ├─ HybridSearchService.search(KB) → chunks
│  │  ├─ Optional JinaReranker.rerank()
│  │  └─ Build context
│  ├─ StockInjectionService.inject() → append to prompt
│  ├─ OpenRouterService.chat(system_prompt + context + user_message)
│  ├─ StockGuardService.validate(response)
│  └─ SemanticCacheService.cache(response)
├─ Return response
└─ Message.create() in DB
         ↓
LINEService.send() / TelegramService.send() / FacebookService.send()
         ↓
Customer receives response on channel
```

### 5.2 RAG Knowledge Base Pipeline

```
User uploads document to KB via Frontend
         ↓
DocumentController.store() (rate limited)
├─ Store file in S3 (Flysystem)
├─ Create Document model
└─ Enqueue ProcessDocument job
         ↓
ProcessDocument job executes
├─ DocumentParserService.parse(file) → raw text
├─ ChunkingService.chunk(text) → 512-token chunks with overlap
├─ Loop: Create DocumentChunk records
├─ EmbeddingService.embed(chunk_text) → vector via OpenRouter
└─ Insert chunk + vector into DB (pgvector column)
         ↓
HybridSearchService.search(kb_id, query) uses:
├─ SemanticSearchService (vector search, pgvector <-> operator)
├─ KeywordSearchService (full-text search, GiST index)
└─ Reciprocal Rank Fusion (RRF) to merge results
         ↓
JinaRerankerService.rerank(results) (optional, if enabled)
├─ Call Jina API for semantic ranking
└─ Return top-K sorted by relevance
         ↓
RAGService builds context from results
└─ Append to system prompt
```

### 5.3 Order Processing

```
Customer mentions product or payment in chat
         ↓
IntentAnalysisService detects order intent
         ↓
PaymentFlexService analyzes response for payment keywords
├─ Detect: "price", "add to cart", "checkout", "payment"
└─ If detected: Flag as order context
         ↓
StockGuardService validates against ProductStock
├─ If out-of-stock product mentioned:
│  ├─ Check if upsell or main product
│  └─ Strip/block response accordingly
└─ Otherwise: Allow response
         ↓
Optional: OrderService.create() if explicit order received
├─ From: manual admin input, webhook payload, or agent decision
└─ Create Order + OrderItems
```

---

## 6. Data Models & Schema Highlights

### 6.1 Core Tables

**`bots` table**:
```sql
id, user_id, name, description, status
channel_type (LINE|TELEGRAM|FACEBOOK)
channel_access_token (encrypted), channel_secret (encrypted)
webhook_url, webhook_forwarder_enabled
-- LLM Settings
primary_chat_model, fallback_chat_model, decision_model
llm_temperature, llm_max_tokens, context_window
system_prompt (nullable, often null → use Flow's)
-- RAG Settings
kb_enabled, kb_relevance_threshold, kb_max_results
-- Routing
use_semantic_router, semantic_router_threshold
use_confidence_cascade, cascade_confidence_threshold
-- Defaults
default_flow_id (FK)
auto_handover (boolean)
-- Stats
total_conversations, total_messages, last_active_at
deleted_at (soft delete)
```

**`flows` table**:
```sql
id, bot_id, name, description
system_prompt, temperature, max_tokens, language
agentic_mode, max_tool_calls, enabled_tools (JSON array)
-- Agent Safety
agent_timeout_seconds, agent_max_cost_per_request
hitl_enabled, hitl_dangerous_actions (JSON array)
-- Second AI (optional)
second_ai_enabled, second_ai_options (JSON)
is_default
deleted_at (soft delete)
```

**`conversations` table**:
```sql
id, bot_id, customer_profile_id, external_customer_id
channel_type, telegram_chat_type, telegram_chat_title
status (active|closed|handover|awaiting_approval)
is_handover (boolean), assigned_user_id (FK, nullable)
memory_notes (JSON), tags (JSON array), context (JSON)
current_flow_id, message_count, unread_count
last_message_at, last_message_id, context_cleared_at
recovery_attempts, last_recovery_at
deleted_at (soft delete)
```

**`messages` table**:
```sql
id, conversation_id, user_id (nullable, for admin), role (user|assistant)
content, metadata (JSON — channel-specific data)
created_at
-- Indexes: conversation_id DESC, created_at DESC (for fast pagination)
```

**`document_chunks` table**:
```sql
id, document_id, chunk_text (TEXT)
embedding (pgvector type — 1536-dim, depends on embedding model)
context (text field for source doc info)
created_at
-- Indexes: pgvector GiST on embedding column (for <-> similarity)
```

**`orders` table**:
```sql
id, bot_id, conversation_id (nullable), customer_profile_id
status (pending|paid|shipped|delivered|cancelled)
total_price, notes
created_at, updated_at
```

**`order_items` table**:
```sql
id, order_id, product_id (slug), name, sku, quantity, price_per_unit, variant (JSON)
```

**`product_stocks` table**:
```sql
id, slug (unique), name, in_stock (boolean), aliases (JSON array)
display_order, created_at, updated_at
-- Cache key: "product_stocks:all" (5-min TTL)
```

---

## 7. Caching Strategy

| Cache Type | TTL | Key Pattern | Purpose |
|-----------|-----|------------|---------|
| **Flow Config** | 30 min | `bot:{id}:default_flow` | Reduce DB queries for flow lookup |
| **Product Stock** | 5 min | `product_stocks:all` | In-memory stock status |
| **Semantic Cache** | 24h | (vector-based) | Cache LLM responses by prompt similarity |
| **Conversation Cache** | Session | In-memory Zustand | Frontend state (messages, active bot) |
| **Query Cache** | React Query | TanStack default | HTTP response caching |
| **RAG Cache** | Configurable | `rag_cache:*` | Store retrieval results |

**Cache Invalidation**:
- Flow created/updated → `FlowCacheService::invalidateBot($botId)`
- Stock updated → `Cache::forget('product_stocks:all')`
- Document chunk added → Semantic cache must be invalidated manually

---

## 8. Security & Auth

### 8.1 Authentication Flow

```
Frontend: /login → AuthController.login()
   ↓
Sanctum: Generate API token
   ↓
Frontend: Store token in localStorage
   ↓
API client: Attach "Authorization: Bearer {token}" header
   ↓
Middleware: auth:sanctum validates token per request
   ↓
Unauthorized? → 401 → Frontend clears token + redirects to login
```

### 8.2 Authorization

**Policies**:
- BotPolicy — User can only access own bots (via user_id FK)
- DocumentPolicy — User can access KB only if user owns bot linked to KB
- Same for Flows, Conversations, Knowledge Bases

**Role-Based** (optional):
- User roles: `owner`, `admin` (stored in users.role)
- AdminBotAssignment table for admins to access specific bots

### 8.3 Encryption

- Channel credentials encrypted at rest: `EncryptedWithFallback` cast
- Fallback support: Decrypt new format, fall back to plaintext if key mismatch
- API keys stored in user_settings table (encrypted)

---

## 9. Deployment Architecture (Railway)

**Components**:
1. **Backend Service** — Laravel app + queue worker (same container)
2. **Frontend Service** — React SPA, compiled to static assets, served via Vite dev server (dev) or Express (prod)
3. **PostgreSQL** — Neon managed database
4. **Reverb** — WebSocket server (can be co-located or separate Railway service)
5. **Redis** (optional) — Session/cache store

**Key Environment Variables** (Railway config):
```
VITE_API_URL=https://api.example.com/api
OPENROUTER_API_KEY=sk_or_...
DATABASE_URL=postgres://...@neon.tech/...
REVERB_HOST / REVERB_PORT
LINE_CHANNEL_ACCESS_TOKEN (legacy, now per-user)
TELEGRAM_BOT_TOKEN (legacy, now per-user)
FACEBOOK_PAGE_ACCESS_TOKEN (legacy, now per-user)
```

---

## 10. Summary Table: Core Services

| Service | File | Responsibility | Key Dependencies |
|---------|------|-----------------|------------------|
| **RAGService** | `Services/RAGService.php` | Orchestrate response generation | OpenRouter, HybridSearch, IntentAnalysis, Cache, Stock |
| **OpenRouterService** | `Services/OpenRouterService.php` | LLM API wrapper | HTTP client (Guzzle), config |
| **HybridSearchService** | `Services/HybridSearchService.php` | Semantic + keyword search, RRF | SemanticSearch, KeywordSearch |
| **FlowCacheService** | `Services/FlowCacheService.php` | Cache Flow config (30-min TTL) | Cache facade |
| **StockInjectionService** | `Services/StockInjectionService.php` | Inject stock status into prompt | ProductStock model, Cache |
| **StockGuardService** | `Services/StockGuardService.php` | Post-gen validation | StockInjection, regex patterns |
| **PaymentFlexService** | `Services/PaymentFlexService.php` | Detect payment flow from response | Keyword matching |
| **LINEService** | `Services/LINEService.php` | Send/receive LINE messages | HTTP client |
| **TelegramService** | `Services/TelegramService.php` | Send/receive Telegram messages | HTTP client |
| **FacebookService** | `Services/FacebookService.php` | Send/receive Facebook messages | HTTP client |
| **DocumentParserService** | `Services/DocumentParserService.php` | Extract text from files | PDF parser |
| **ChunkingService** | `Services/ChunkingService.php` | Split text into chunks | Text manipulation |
| **EmbeddingService** | `Services/EmbeddingService.php` | Generate embeddings | OpenRouter embedding endpoint |
| **LeadRecoveryService** | `Services/LeadRecoveryService.php` | Re-engage inactive customers | Task scheduling |
| **MessageAggregationService** | `Services/MessageAggregationService.php` | Batch multiple messages | Timing logic |
| **CircuitBreakerService** | `Services/CircuitBreakerService.php` | Resilience for external APIs | State tracking |

---

## 11. Known Gotchas (from CLAUDE.md + Retrospectives)

1. **`config('x','')` returns null** → Use `config('x') ?? ''` instead
2. **API responses wrapped** → Access `response.data` in frontend
3. **N+1 queries** → Use `->with()` eager loading
4. **Flow vs Bot distinction** → Flow is the config blueprint, Bot is the instance
5. **Prompt location** → Often in `Flow.system_prompt`, not `Bot.system_prompt`
6. **Stock Guard false positive** → Don't block on "payment" line items (payment_method field)
7. **`memory_notes` type ambiguity** → Can be `array` or `{vip:true}` object → validate with `array_is_list()`
8. **vision model regression** → `supportsVision()` was too strict, causing silent failures
9. **Database discrepancy** → Local SQLite ≠ Production Neon → Use `mcp__neon__run_sql` for queries
10. **Cache invalidation** → Change `flows.system_prompt`? Must call `Cache::forget('bot:{id}:default_flow')`

---

**Document Version**: 1.0  
**Last Updated**: 2026-04-16  
**Scope**: Architecture, entry points, abstractions, dependencies, deployment
