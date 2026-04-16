# BotFacebook Quick Reference Guide

**Last Updated:** 2026-04-16  
**Stack:** Laravel 12 + React 19 + PostgreSQL (Neon) + Railway + Reverb WebSocket  
**Channels:** LINE Messaging API, Telegram  

---

## 1. What It Does (Comprehensive Overview)

**BotFacebook** is an AI-powered conversational chatbot platform that connects with LINE and Telegram. It uses Retrieval Augmented Generation (RAG) to answer questions based on custom knowledge bases while supporting advanced features like stock management, VIP tiers, flexible payment responses (Payment Flex), and real-time dashboards.

**Core Capabilities:**
- **RAG Pipeline** — Semantic search (pgvector) + hybrid search (keyword + RRF) + reranking (Jina/Cohere) for intelligent KB retrieval
- **Stock Management** — DB-driven inventory with automatic StockGuard validation (blocks selling out-of-stock items)
- **Multi-Model Support** — 33+ LLM models from OpenRouter (GPT-4o, Claude, Deepseek, Jina, etc.) with pricing & capability tracking
- **VIP System** — Customer tiers with dedicated prompts and behavior flags
- **Payment Flex** — Auto-converts text (e.g., "ชำระแล้ว") to rich Flex messages
- **Real-time Dashboard** — Owner-only analytics with Reverb WebSocket updates
- **Semantic Cache** — pgvector-based response caching (30-50% cost reduction, 100ms latency)
- **CRAG (Corrective RAG)** — Auto-detects poor retrieval and reranks/rewrites queries
- **Memory & Conversation History** — Up to 20 messages per conversation with adaptive context window

---

## 2. Setup / Installation

### Backend Setup

```bash
cd backend

# Copy .env and configure
cp .env.example .env

# Key env vars to set:
# OPENROUTER_API_KEY=sk-or-xxx        # For LLM API calls
# LINE_CHANNEL_ACCESS_TOKEN=xxx        # LINE webhook
# LINE_CHANNEL_SECRET=xxx              # LINE signature validation
# DB_CONNECTION=sqlite                 # Local dev (change to postgres for prod)
# REVERB_APP_KEY=local-key            # WebSocket auth
# EMBEDDING_MODEL=openai/text-embedding-3-small

# Install dependencies & setup DB
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed  # Optional: seed test data
```

### Frontend Setup

```bash
cd frontend

# Copy .env and configure
cp .env.example .env

# Key env vars:
# VITE_API_URL=http://localhost:8000/api
# VITE_REVERB_APP_KEY=local-key
# VITE_REVERB_HOST=localhost
# VITE_REVERB_PORT=8080

# Install dependencies
npm install
```

### Database Requirements

- **PostgreSQL** with `pgvector` extension (for semantic embeddings)
- **Neon** (Production) — Project: `solitary-math-34010034`
- **SQLite** (Local dev) — `database/database.sqlite` (auto-created)

```bash
# Check pgvector installation (production only)
psql -c "SELECT * FROM pg_extension WHERE extname = 'vector';"

# If not installed:
# CREATE EXTENSION vector;
```

---

## 3. Daily Commands

### Run Everything Locally

```bash
# 🚀 One-shot: Start server + queue + logs + Vite
cd backend
composer run dev

# This runs in parallel:
# - Server:     php artisan serve
# - Queue:      php artisan queue:listen --tries=1
# - Logs:       php artisan pail --timeout=0 (real-time log tail)
# - Vite:       npm run dev (from frontend/)
```

### Backend Commands

```bash
cd backend

# ✅ Testing
php artisan test                       # All tests
php artisan test --filter=Unit         # Unit tests
php artisan test --filter=PaymentFlex  # Specific test suite
php artisan test --filter=RAGService   # RAG tests

# 🎨 Code Style
vendor/bin/pint --test                 # Check style (CI uses this)
vendor/bin/pint                        # Auto-fix style

# 💾 Database
php artisan migrate                    # Apply migrations
php artisan migrate:rollback           # Undo last batch
php artisan migrate:reset              # Wipe & replay all
php artisan db:seed                    # Seed test data
php artisan tinker                     # REPL for debugging

# 📨 Queue & Jobs
php artisan queue:listen               # Background jobs worker
php artisan queue:failed               # List failed jobs
php artisan queue:retry all            # Retry failed jobs
php artisan queue:clear                # Clear all jobs

# 🔄 Cache & Config
php artisan cache:clear
php artisan config:cache
php artisan config:clear

# 🌐 Reverb WebSocket (optional separate terminal)
php artisan reverb:start               # Real-time messaging server
```

### Frontend Commands

```bash
cd frontend

# 🚀 Development
npm run dev                            # Start Vite dev server (http://localhost:5173)
npm run build                          # Production build
npm run preview                        # Preview production build

# 🎨 Code Quality
npm run lint                           # ESLint check (CI uses this)
npx tsc --noEmit                       # TypeScript type check
npx knip --reporter compact            # Dead code scan

# 🧪 Testing
npm test                               # Run tests once
npm run test:watch                     # Watch mode
npm run test:coverage                  # Coverage report
```

### Production Database (Neon)

```bash
# Use mcp__neon__run_sql for queries:
# Example via tinker:
cd backend
php artisan tinker

# Inside tinker:
> DB::connection('neon')->select('SELECT COUNT(*) FROM conversations');
> DB::table('bots')->where('id', 1)->first();
```

### Cache Invalidation

```bash
# Clear Flow config cache (30-min TTL normally)
# When: After editing bot system prompt or flows
cd backend
php artisan tinker

# Inside tinker:
> Cache::forget('bot:123:default_flow');
> Cache::forget('bot:123:has_flows');
> Cache::forget('all_models');  # Clears model list cache
```

---

## 4. Key Features (How to Use Each)

### RAG / Knowledge Base Retrieval

**Location:** `backend/app/Services/RAGService.php`  
**Config:** `backend/config/rag.php`

```php
// Trigger RAG in Flow System Prompt:
// "Use the Knowledge Base to answer: {context}"
// Bot will auto-inject KB chunks here

// RAG Settings (in config/rag.php):
'default_threshold' => 0.70,      // Relevance threshold
'max_results' => 3,                // KB chunks to include
'max_context_chars' => 4000,      // Token limit for KB
'hybrid_search' => ['enabled' => true],  // Semantic + keyword
```

**Hybrid Search (Semantic + Keyword):**
- Fetches top K results from vector search + full-text search
- Merges via Reciprocal Rank Fusion (RRF, k=60)
- ~48% better retrieval than semantic alone

**Semantic Cache (pgvector):**
- Caches similar queries to save API costs
- Threshold: 0.92 similarity → cache hit
- TTL: 60 minutes
- Skip cache for: short messages (<20 chars), context keywords (ยืนยัน, ตกลง, etc.), ongoing conversations

### Stock Management & StockGuard

**Location:** `backend/app/Services/StockGuardService.php`  
**Config:** `backend/config/rag.php` > `stock_guard`

```php
// StockGuard validates LLM response AFTER generation
// If response mentions out-of-stock product → block/edit response

// Stock data:
// - products table: id, name, sku, is_active, stock_quantity
// - inventory_logs: track changes

// Enable/disable:
env('STOCK_GUARD_ENABLED', true)  // in .env
```

**How it works:**
1. LLM generates response about products
2. StockGuard extracts product mentions
3. Checks DB for stock status
4. If out-of-stock → removes mention or blocks response
5. Fallback: "ขออภัย สินค้านี้หมดสต็อก"

### VIP System

**Location:** `backend/app/Models/CustomerProfile.php`  
**Schema:** `customers` table > `vip_tier` column

```php
// VIP Tiers:
// 1: Standard
// 2: Gold  
// 3: Platinum
// 4: Diamond

// Customize per-tier:
// - Use memory_notes to store VIP flags: { vip: true }
// - Flow can check: @memory_notes contains vip
// - System prompt can reference: "This is VIP customer"
```

### Payment Flex Auto-Conversion

**Location:** `backend/app/Services/PaymentFlexService.php`

```php
// Detects payment keywords & converts to Flex message
// Examples: "จ่ายแล้ว", "โอนแล้ว", "ชำระแล้ว"

// Outputs rich Flex message with payment confirmation

// Config location:
// backend/app/Services/PaymentFlexService.php → detection rules
// Edit keyword list there before changing prompts
```

### Dashboard (Owner-Only Views)

**Location:** `frontend/src/pages/` > Dashboard pages  
**Protected by:** `auth` middleware + role check

**Views Available:**
- Overview — Chat/bot stats, conversation count
- Orders — Payment & product orders (with order status)
- Customers — VIP tiers, interaction history
- Bots — CRUD: create/edit/delete bots
- Settings — Bot config, webhook URLs, prompt editing
- Costs — Token usage breakdown by model/bot

**Real-time Updates:** Uses Reverb WebSocket for live metrics

### CRAG (Corrective RAG)

**Config:** `backend/config/rag.php` > `crag`

```php
'crag' => [
    'enabled' => env('RAG_CRAG_ENABLED', false),  // Disabled by default
    'evaluation_mode' => 'heuristics',  // 'heuristics', 'llm', 'hybrid'
    'correct_threshold' => 0.7,  // Accept results
    'ambiguous_threshold' => 0.3,  // Rewrite query
    'max_rewrite_attempts' => 2,
    'incorrect_action' => 'skip_kb',  // Skip KB if grade=incorrect
],
```

**When Enabled:**
1. Evaluates if retrieved KB chunks are relevant
2. If grade="correct" → use results
3. If grade="ambiguous" → rewrite query & retry
4. If grade="incorrect" → skip KB, use LLM only

---

## 5. Configuration Reference

### LLM Models Config

**File:** `backend/config/llm-models.php`  
**API:** OpenRouter

```php
// 33+ supported models with metadata:
'models' => [
    'openai/gpt-4o' => [
        'name' => 'GPT-4o',
        'provider' => 'openai',
        'context_length' => 128000,
        'supports_vision' => true,
        'supports_structured_output' => true,
        'pricing_prompt' => 2.5,      // USD per 1M tokens
        'pricing_completion' => 10.0,
    ],
    'openai/gpt-4o-mini' => [...],     // Cheaper alternative
    'openai/gpt-5-mini' => [...],      // With mandatory reasoning
    'anthropic/claude-opus-4-1' => [...],
    // ... more models
],
```

**Common Model Selection:**
- **Speed & Cost:** `gpt-4o-mini` (0.15/$0.6 per 1M tokens)
- **Reasoning:** `gpt-5-mini` (mandatory), `o1-mini` (optional)
- **Vision:** Any with `supports_vision: true`
- **Structured JSON:** Any with `supports_structured_output: true`

### RAG Config Deep Dive

**File:** `backend/config/rag.php`

```php
// Relevance threshold (0-1)
// 0.8+: strict, only perfect matches
// 0.7: balanced (default)
// 0.5-0.6: lenient, may be noisy
'default_threshold' => 0.70,

// Hybrid search (Semantic + Keyword)
'hybrid_search' => [
    'enabled' => true,
    'rrf_k' => 60,                    // Standard RRF constant
    'candidate_multiplier' => 4,      // Fetch 4x results before fusion
],

// Reranking (Phase 2 — disabled by default)
'reranking' => [
    'enabled' => false,
    'provider' => 'jina',             // 'jina' or 'cohere'
    'candidates' => 20,               // Score top 20
    'top_n' => 5,                     // Keep top 5
],

// Query Enhancement (Phase 3 — disabled)
'query_enhancement' => [
    'enabled' => false,
    'model' => 'openai/gpt-4o-mini',  // Expand query to 3 variations
    'max_variations' => 3,
],

// Contextual Retrieval (Anthropic technique)
'contextual_retrieval' => [
    'enabled' => true,
    'model' => 'openai/gpt-5-mini',   // Generate summaries
],

// Semantic Cache (pgvector)
'semantic_cache' => [
    'enabled' => true,
    'similarity_threshold' => 0.92,   // High threshold = fewer false hits
    'ttl_minutes' => 60,              // Cache for 1 hour
    'skip_patterns' => [
        '/^(ยืนยัน|ตกลง|ใช่|ok)$/iu',  // Skip context-dependent
    ],
],

// Semantic Router (intent classification)
'semantic_router' => [
    'enabled' => true,
    'default_threshold' => 0.75,      // 75% confidence required
    'fallback' => 'llm',              // Fall back to LLM if uncertain
],

// Adaptive Temperature
'adaptive_temperature' => [
    'enabled' => true,
    'knowledge_max' => 0.3,           // Low for KB questions (factual)
    'chat_min' => 0.6,                // High for chat (creative)
],
```

### Critical .env Variables

**Backend:**
```bash
# AI Model
OPENROUTER_API_KEY=sk-or-xxx
OPENROUTER_MODEL=openai/gpt-4o-mini

# LINE Webhook (optional)
LINE_CHANNEL_ACCESS_TOKEN=xxx
LINE_CHANNEL_SECRET=xxx

# Embedding Model
EMBEDDING_MODEL=openai/text-embedding-3-small
EMBEDDING_DIMENSIONS=1536
EMBEDDING_CHUNK_SIZE=500

# RAG Thresholds
RAG_THRESHOLD=0.70
RAG_MAX_RESULTS=3
RAG_SEMANTIC_CACHE_ENABLED=true
RAG_SEMANTIC_CACHE_THRESHOLD=0.92

# Real-time WebSocket
REVERB_APP_KEY=your-key
REVERB_HOST=localhost
REVERB_SCHEME=http

# Database (Neon production)
DB_CONNECTION=postgres
DB_HOST=ep-xxxx.neon.tech
DB_USERNAME=neondb
DB_PASSWORD=xxx
DB_DATABASE=neondb
```

**Frontend:**
```bash
VITE_API_URL=http://localhost:8000/api
VITE_REVERB_APP_KEY=your-key
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```

---

## 6. Directory Cheat Sheet

```
backend/
├── app/
│   ├── Services/           # 45+ services (RAGService, PaymentFlexService, etc.)
│   ├── Models/             # 26 models (Bot, Conversation, Customer, etc.)
│   ├── Jobs/               # 8 async jobs (ProcessMessage, GenerateEmbedding, etc.)
│   ├── Http/
│   │   ├── Controllers/    # API endpoints
│   │   └── Middleware/     # Auth, CORS validation
│   ├── Events/             # Broadcast events (MessageSent, BotCreated)
│   └── Listeners/
├── config/
│   ├── llm-models.php      # 33+ model definitions + pricing
│   ├── rag.php             # RAG pipeline config
│   ├── agent-prompts.php   # System prompt templates
│   └── broadcasting.php    # Reverb WebSocket config
├── database/
│   ├── migrations/         # Schema changes
│   ├── seeders/            # Test data
│   └── database.sqlite     # Local SQLite DB
├── routes/
│   └── api.php             # All API routes
├── tests/                  # PHPUnit + Pest tests
└── vendor/                 # Dependencies (DO NOT EDIT)

frontend/
├── src/
│   ├── components/         # React components
│   │   ├── chat/          # Chat UI (messages, input, etc.)
│   │   ├── conversation/  # Conversation management
│   │   ├── telegram/      # Telegram-specific UI
│   │   ├── line/          # LINE-specific UI
│   │   ├── ui/            # Generic UI (buttons, dialogs, etc.)
│   │   ├── dashboard/     # Analytics & admin
│   │   └── ...13+ dirs
│   ├── pages/             # 12+ pages (Chat, Dashboard, Settings, etc.)
│   ├── hooks/             # ~25 custom hooks (useBot, useConversation, etc.)
│   ├── stores/            # Zustand stores
│   │   ├── auth.ts        # Auth state
│   │   ├── chat.ts        # Chat messages
│   │   ├── ui.ts          # UI state (modals, theme)
│   │   ├── botPreferences.ts  # Bot config
│   │   └── connection.ts  # WebSocket status
│   ├── lib/
│   │   └── api.ts         # Axios client with interceptors
│   ├── assets/            # Images, icons
│   └── App.tsx            # Root component
├── package.json           # Dependencies
└── node_modules/          # Dependencies (DO NOT EDIT)
```

---

## 7. Common Gotchas & Solutions

### Code-Level Gotchas

| Problem | Solution | Example |
|---------|----------|---------|
| `config('x', 'default')` returns null | Use `??` operator | `config('x') ?? 'default'` |
| API response wrapped in `{data: {}}` | Access `.data.data` or use axios interceptor | `response.data.data` |
| N+1 query explosion | Eager load relations | `Bot::with('user', 'conversations')->get()` |
| Race condition on profile creation | Use `lockForUpdate()` in transaction | `CustomerProfile::lockForUpdate()->first()` |
| TypeScript interface ≠ Laravel model | Sync `api.ts` with model `$fillable` & `$casts` | Verify import paths |

### Feature-Level Gotchas

| Problem | Solution |
|---------|----------|
| Flow ≠ Bot | Flows are central config; bots reference them |
| Prompt is null | System prompt lives in `flows.system_prompt`, not `bots.system_prompt` |
| Cache stale after prompt edit | Clear: `Cache::forget('bot:{ID}:default_flow')` |
| `memory_notes` becomes object | Some conversations have `{vip: true}` instead of array → validate with `array_is_list()` |
| StockGuard false positive | Check extraction logic; may need adjustment if product names are ambiguous |

### Data-Level Gotchas

| Problem | Solution |
|---------|----------|
| Local DB ≠ Production | Local uses SQLite; production is Neon Postgres. Use `mcp__neon__run_sql` for prod queries |
| Thai search not finding results | Lower relevance threshold from 0.8 to 0.7; improve tokenization |
| Semantic cache hits wrong query | Similarity threshold too low (default 0.92 is good); adjust if needed |
| Webhook not receiving LINE messages | Check: HTTPS only, webhook URL valid, signature verification, 200 response within 3s |

### Deployment Gotchas

| Problem | Solution |
|---------|----------|
| Railway build fails: "Cannot find @vitejs/plugin-react" | Move build tools from `devDependencies` to `dependencies` in package.json |
| Env vars not working after deploy | Set in Railway dashboard, wait for auto-restart, verify with `railway logs` |
| Reverb WebSocket fails | Check: REVERB_ALLOWED_ORIGINS uses hostnames only (no `https://`), PORT matches, SCHEME is correct |
| Test fails on CI but passes locally | Set env vars properly in CI: DB_CONNECTION=sqlite, CACHE_DRIVER=array, etc. |

---

## 8. One-Minute Troubleshooting

```bash
# Error: "config() returns null"
# Fix: Use config('key') ?? 'default'

# Error: "API returns {data: {id: 1}}"
# Fix: Access response.data.data or configure axios interceptor

# Error: "N+1 queries slowing bot"
# Fix: Use ->with('relation') in queries

# Error: "Component not rendering"
# Fix: grep -r "ComponentName" src/ — is it imported?

# Error: "Deploy succeeded but old code"
# Fix: Check deploymentId has commitHash — if empty, cache was used

# Error: "WebSocket not updating"
# Fix: Check Reverb connection: Echo.connector.pusher.connection.state

# Error: "Thai search not finding results"
# Fix: Lower threshold from 0.8 to 0.7

# Error: "StockGuard blocks valid response"
# Fix: Check detection rules in PaymentFlexService.php before editing prompt

# Error: "Prompt edit didn't take effect"
# Fix: Clear cache: php artisan tinker > Cache::forget('bot:ID:default_flow')

# Error: "Memory_notes is object, not array"
# Fix: Validate with array_is_list() before using as array
```

---

## 9. Testing & Verification

### Run Tests

```bash
cd backend

# All tests
php artisan test

# Specific test file
php artisan test tests/Unit/PaymentFlexServiceTest.php

# Watch mode (auto-rerun on change)
php artisan test --watch

# With coverage
php artisan test --coverage
```

### Code Style & Quality

```bash
cd backend

# Check style issues
vendor/bin/pint --test

# Auto-fix
vendor/bin/pint

# Type check (frontend)
cd ../frontend
npx tsc --noEmit

# Dead code scan
npx knip --reporter compact
```

### Local Verification Before Commit

```bash
# 1. Backend tests pass
cd backend
php artisan test

# 2. Code style OK
vendor/bin/pint --test

# 3. Frontend type check
cd ../frontend
npx tsc --noEmit

# 4. Frontend lint
npm run lint

# 5. No debug statements left
grep -r "console.log\|dd()\|dump(" backend/app/ frontend/src/ | grep -v node_modules
```

---

## 10. Quick Reference Tables

### Commit Message Format

```
feat:  Add X feature
fix:   Fix Y bug
chore: Update Z dependency
docs:  Update README
```

### Model Layer Priority

```
GPT-4o (2.5/$10)    — Most capable, vision, structured output
GPT-4o Mini (0.15/$0.6) — Fastest, cheap, default choice
GPT-5-Mini (0.25/$2)    — Reasoning, better Thai understanding
Claude Opus (2/$15)      — Best long-context reasoning
Deepseek (0.14/$0.28)   — Cost-effective reasoning
```

### RAG Pipeline Tuning

```
Problem              | Adjust Setting          | Impact
Poor retrieval       | ↓ threshold 0.7 → 0.6  | More results, noisier
Too many results     | ↑ threshold 0.7 → 0.8  | Fewer, stricter matches
Missing Thai content | Enable hybrid_search    | +48% recall
Slow responses       | Cache enabled=true      | Cache hit → 100ms
```

---

## References

- **CLAUDE.md** — Full project guide (in repo root)
- **docs/gotchas.md** — Extended gotchas & solutions
- **docs/testing.md** — Testing strategies
- **docs/security.md** — Auth & security checklist
- **config/llm-models.php** — All 33+ models & pricing
- **config/rag.php** — RAG pipeline settings (600+ LOC)
- **routes/api.php** — All API endpoints
- **app/Services/** — Business logic (RAGService, PaymentFlexService, etc.)

---

**Last Sync:** Git commit cc9cc68 (feat: redesign dashboard with modern SaaS styling #125)
