# Dabby.io Clone - Comprehensive Development Plan

**Project**: AI-Powered Chatbot Platform (Like Dabby.io)
**Repository**: https://github.com/JaoChai/bot-fb
**Created**: December 23, 2025
**Status**: Planning Phase

---

## 📊 Executive Summary

This document outlines the complete development plan for building a Dabby.io clone - a no-code/low-code AI chatbot platform supporting LINE OA and Facebook Messenger with knowledge base integration, agentic AI, and real-time chat management.

**Key Metrics**:
- **Estimated Duration**: 8-12 weeks (3 phases)
- **Team Size**: 1-3 developers
- **Technology**: Laravel 12 + React 19 + PostgreSQL (Neon) + pgvector
- **Target Users**: Thai small-medium businesses

---

## 🏗️ System Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                     DABBY.IO CLONE                          │
├─────────────────────────────────────────────────────────────┤
│
│  FRONTEND LAYER (React 19)
│  ├─ Dashboard & Analytics
│  ├─ Bot Builder / Flow Editor
│  ├─ Chat Management Interface
│  ├─ Knowledge Base Manager
│  ├─ Settings & Configuration
│  └─ Real-time Chat Emulator
│
│  BACKEND LAYER (Laravel 12)
│  ├─ Authentication (Sanctum)
│  ├─ REST API (30+ endpoints)
│  ├─ Real-time Broadcasting (Laravel Reverb)
│  ├─ Queue System (Redis)
│  ├─ Message Processing Engine
│  ├─ AI Integration (OpenAI SDK)
│  └─ Webhook Handlers
│
│  DATABASE LAYER (Neon.tech PostgreSQL)
│  ├─ Users & Subscriptions
│  ├─ Bots/Connections
│  ├─ Flows & Prompts
│  ├─ Conversations & Messages
│  ├─ Knowledge Base (with pgvector)
│  ├─ Customer Profiles
│  └─ Embeddings (vector search)
│
│  EXTERNAL SERVICES
│  ├─ OpenAI API (GPT-4, Embeddings)
│  ├─ LINE Messaging API
│  ├─ Facebook Messenger API
│  ├─ EasySlip API (payment verification)
│  ├─ Laravel Reverb (WebSocket)
│  └─ AWS S3 (file storage)
│
└─────────────────────────────────────────────────────────────┘
```

---

## 💾 Technology Stack - Final Decisions

### Backend: Laravel 12 + PHP 8.4

| Component | Technology | Version | Reason |
|-----------|-----------|---------|--------|
| Framework | Laravel | 12.x | Latest, excellent for APIs |
| PHP | PHP | 8.4 LTS | Latest LTS, better performance |
| Auth | Sanctum | 4.x | Simple, perfect for first-party API |
| Real-Time | **Reverb** | Latest | **FREE** (saves $49-499/mo vs Pusher) |
| Queues | Redis | Latest | Fastest, minimal setup, Horizon dashboard |
| File Storage | AWS S3 | - | Industry standard, reliable |
| LLM Integration | OpenAI SDK | ^0.8.0 | Native Laravel support |
| Debugging | Telescope | 5.x | Dev tool, built-in |
| Testing | Pest | 3.x | Modern, better than PHPUnit |

### Database: Neon.tech PostgreSQL + pgvector

| Component | Technology | Decision | Reason |
|-----------|-----------|----------|--------|
| Database | Neon.tech | ✅ Chosen | Free tier, pgvector built-in, scalable |
| Vector DB | pgvector | ✅ Built-in | No separate service needed, cost-effective |
| Connection Pooling | PgBouncer | ✅ Included | 10,000 concurrent connections |
| Scaling | Autoscaling | ✅ Available | Grow automatically with load |
| Backups | Point-in-time | ✅ Included | Recover to any point in time |

**Alternative Vector DBs Considered**:
- ❌ Pinecone: Free tier too limited, expensive at scale
- ⚠️ Weaviate: Good but self-hosting complex
- ❌ Milvus: Overkill for MVP, complex setup
- ✅ pgvector: Best for our use case (integrated, cost-effective)

### Frontend: React 19 + Vite

| Component | Technology | Version | Reason |
|-----------|-----------|---------|--------|
| Framework | React | 19.2+ | Latest, React Compiler for optimization |
| Bundler | Vite | 5.x | Built into Laravel, lightning-fast HMR |
| State (Server) | TanStack Query | 5.x+ | 2025 standard, Suspense support |
| State (Client) | Zustand | 5.x | Simple, lightweight, no boilerplate |
| Real-Time | Laravel Echo | 1.16+ | Works with Laravel Reverb |
| Form Validation | React Hook Form + Zod | Latest | Type-safe, minimal re-renders |
| Rich Text | TipTap | 2.x | Headless, fully customizable |
| UI Components | shadcn/ui | Latest | 220+ templates, React 19 ready |
| Styling | Tailwind v4 | 4.x | Integrated with shadcn/ui |

### External Services

| Service | Purpose | Cost | Notes |
|---------|---------|------|-------|
| **OpenAI API** | LLM (GPT-4o, Embeddings) | Pay-per-token | Start with this, optional OpenRouter later |
| **LINE Messaging** | Chat channel | Free for dev | Production has per-message costs |
| **Facebook Messenger** | Chat channel | Free for dev | Production has per-message costs |
| **EasySlip API** | Payment verification | Usage-based | Thailand-specific, integration ready |
| **AWS S3** | File storage | $0.023/GB stored | For KB documents, images |

---

## 📋 Implementation Phases

### Phase 1: MVP Foundation (Weeks 1-3)
**Goal**: Get basic chatbot working end-to-end

#### Phase 1A: Backend Setup (Week 1)
- [ ] Setup Laravel 12 project structure
- [ ] Configure Neon.tech PostgreSQL + pgvector
- [ ] Implement user authentication (Sanctum)
- [ ] Create database migrations (users, bots, conversations, messages)
- [ ] Setup Redis for caching
- [ ] Implement basic API endpoints
- [ ] Setup logging & error handling

**Deliverables**:
- Working Laravel backend
- Database schema complete
- Auth system functional
- 10+ API endpoints

#### Phase 1B: Frontend Setup (Week 1)
- [ ] Initialize React 19 + Vite with shadcn/ui
- [ ] Setup TanStack Query + Zustand
- [ ] Create basic page layouts (Dashboard, Bot List)
- [ ] Implement authentication flow (Login/Register)
- [ ] Setup API client with proper error handling

**Deliverables**:
- Working React frontend
- Login/Register pages
- Basic navigation
- Connected to backend API

#### Phase 1C: Core Chat Features (Week 2-3)
- [ ] Setup LINE OA webhook handler (Laravel)
- [ ] Implement message receiving & processing
- [ ] Integrate OpenAI API for bot responses
- [ ] Create simple prompt template system
- [ ] Build chat emulator in React
- [ ] Implement message storage
- [ ] Setup Laravel Reverb for real-time updates

**Deliverables**:
- Bot receives messages from LINE
- Bot generates responses via OpenAI
- Real-time chat interface
- Message history stored

### Phase 2: Core Features (Weeks 4-8)
**Goal**: Add knowledge base, advanced settings, and proper bot management

#### Phase 2A: Knowledge Base System (Week 4-5)
- [ ] Create KB document upload UI (React)
- [ ] Implement document processing backend
- [ ] Generate embeddings via OpenAI API
- [ ] Store embeddings in pgvector
- [ ] Implement semantic search (similarity queries)
- [ ] Integrate KB into bot prompt (RAG system)
- [ ] Create KB management interface

**Deliverables**:
- Upload and manage KB documents
- Documents processed and embedded
- Bot uses KB for more accurate responses
- Similarity search working

#### Phase 2B: Bot Configuration (Week 5-6)
- [ ] Implement all settings (usage limits, HITL, response hours, etc.)
- [ ] Create settings UI in React
- [ ] Flow builder (simple prompt editor)
- [ ] Support multiple flows/templates
- [ ] Test bot configurations

**Deliverables**:
- Complete settings page
- Multiple configuration options
- Flow builder functional
- Bot behavior customizable

#### Phase 2C: Chat Management (Week 6-7)
- [ ] Implement chat view with conversation history
- [ ] Add memory/note system per conversation
- [ ] Add tagging system for conversations
- [ ] Create conversation filtering & search
- [ ] Implement HITL (admin can take over chat)
- [ ] Build conversation analytics

**Deliverables**:
- Chat management interface
- View all conversations
- Add notes/memory
- Tag and organize chats

#### Phase 2D: Subscription & Plans (Week 7-8)
- [ ] Create pricing tiers (Lite, Standard, Pro, Enterprise)
- [ ] Implement subscription management
- [ ] Add rate limiting based on plan
- [ ] Create plans management page
- [ ] Setup payment integration (Stripe/2C2P for Thai market)

**Deliverables**:
- Pricing system working
- Different plans with different limits
- Payment processing setup

### Phase 3: Advanced Features & Polish (Weeks 9-12)
**Goal**: Add advanced AI features, additional channels, deployment

#### Phase 3A: Advanced AI (Week 9-10)
- [ ] Implement Agentic Mode (tool calling)
- [ ] Add function calling for external APIs
- [ ] Implement multi-step reasoning
- [ ] Add second AI for response refinement
- [ ] Support for multiple LLM providers (OpenRouter)
- [ ] Caching for repeated queries

**Deliverables**:
- AI can make decisions, call tools
- Multi-provider LLM support
- Smarter bot responses

#### Phase 3B: Additional Channels (Week 10-11)
- [ ] Add Facebook Messenger integration
- [ ] Add Telegram support (optional)
- [ ] Implement channel switching in UI
- [ ] Multi-channel dashboard

**Deliverables**:
- Bot works on multiple channels
- Unified chat management

#### Phase 3C: Advanced Features (Week 11-12)
- [ ] Lead recovery (proactive messaging)
- [ ] Customer screening
- [ ] Bank slip verification (EasySlip API)
- [ ] Google Sheets integration
- [ ] Webhook system for external APIs
- [ ] Advanced analytics & reporting

**Deliverables**:
- All advertised features working
- Enterprise-ready feature set

#### Phase 3D: Deployment & Optimization (Week 12)
- [ ] Docker setup (dev & production)
- [ ] GitHub Actions CI/CD pipeline
- [ ] Deploy to staging (Railway/Render)
- [ ] Performance optimization
- [ ] Security audit
- [ ] Documentation
- [ ] User guide & help docs

**Deliverables**:
- Production-ready deployment
- Automated testing & deployment
- Complete documentation

---

## 🔧 Detailed Technology Decisions

### Why Laravel Reverb instead of Pusher?

```
┌──────────────────────────────────────────────────────┐
│ Cost Comparison: Monthly Savings                     │
├──────────────────────────────────────────────────────┤
│ Pusher Channels:    $49-499/month                   │
│ Laravel Reverb:     $0 (self-hosted in Laravel)     │
│ Savings:            $49-499/month ✅                │
│                                                      │
│ Performance:                                         │
│ Pusher Latency:     ~150ms                          │
│ Reverb Latency:     ~90-100ms (-40%)                │
│ Result:             Faster + Cheaper ✅             │
│                                                      │
│ Control:                                             │
│ Pusher:             Vendor lock-in                  │
│ Reverb:             Full control, self-hosted ✅    │
└──────────────────────────────────────────────────────┘
```

**Decision**: Use **Laravel Reverb** for MVP
- Fallback to Pusher only if Reverb has issues
- Can switch later without code changes (Pusher-protocol compatible)

### Why Neon.tech + pgvector instead of separate services?

```
Vector DB Comparison:

Pinecone:
├─ Free: 5 indexes, 2GB max (too limited)
├─ Paid: Expensive ($0.40/1K vectors)
├─ Pros: Fully managed
└─ Cons: Vendor lock-in, overkill for MVP

Weaviate:
├─ Free: Open-source (complex setup)
├─ Paid: Variable pricing (hard to estimate)
└─ Cons: Not integrated with database

pgvector (in Neon):
├─ Free: Included in free tier
├─ Integrated: Same database as app data
├─ Performance: HNSW indexes for fast search
├─ Scalable: Unlimited vectors with paid tier
└─ Decision: ✅ BEST for MVP & growth
```

**Cost Breakdown (Monthly)**:
| Scenario | Pinecone | pgvector | Savings |
|----------|----------|----------|---------|
| 1M vectors | $400 | $0-35 | $365-400 |
| 10M vectors | $4,000 | $35-350 | $3,650-3,965 |
| 100M vectors | $40,000 | $350-3,500 | $36,500-39,650 |

---

## 🎯 API Endpoints (Summary)

### Authentication (5 endpoints)
- POST `/api/auth/register` - User registration
- POST `/api/auth/login` - User login
- POST `/api/auth/logout` - User logout
- POST `/api/auth/refresh` - Refresh token
- GET `/api/auth/user` - Get current user

### Bots Management (8 endpoints)
- POST `/api/bots` - Create bot
- GET `/api/bots` - List user's bots
- GET `/api/bots/:id` - Get bot details
- PUT `/api/bots/:id` - Update bot
- DELETE `/api/bots/:id` - Delete bot
- POST `/api/bots/:id/connect` - Connect to channel (LINE/FB)
- GET `/api/bots/:id/webhook-url` - Get webhook URL
- POST `/api/bots/:id/test` - Test bot with emulator

### Knowledge Base (6 endpoints)
- POST `/api/kb` - Create knowledge base
- GET `/api/kb` - List KBs
- POST `/api/kb/:id/upload` - Upload document
- GET `/api/kb/:id/documents` - List documents
- DELETE `/api/kb/:id/documents/:docId` - Delete document
- POST `/api/kb/:id/search` - Search documents (vector search)

### Conversations & Chat (7 endpoints)
- GET `/api/conversations` - List all conversations
- GET `/api/conversations/:id` - Get conversation with messages
- POST `/api/conversations/:id/message` - Send message
- POST `/api/conversations/:id/memory` - Add memory/note
- POST `/api/conversations/:id/tags` - Add tags
- POST `/api/conversations/:id/hitl` - Enable HITL (human takeover)
- GET `/api/conversations/:id/analytics` - Get conversation stats

### Settings (4 endpoints)
- GET `/api/settings` - Get bot settings
- PUT `/api/settings` - Update bot settings
- GET `/api/settings/plans` - List subscription plans
- POST `/api/settings/upgrade` - Upgrade plan

### Webhooks (2 endpoints)
- POST `/webhook/line/:botId/:token` - LINE webhook handler
- POST `/webhook/facebook/:botId/:token` - Facebook webhook handler

**Total: 32 core endpoints** (plus admin/analytics endpoints)

---

## 📊 Database Schema (Key Tables)

### Core Tables
```
users
├─ id, email, password_hash, name
├─ subscription_plan, subscription_expires_at
├─ created_at, updated_at

bots (connections)
├─ id, user_id, name, status
├─ channel_type (LINE, FACEBOOK, DEMO)
├─ api_keys, webhook_url
├─ default_flow_id
├─ created_at, updated_at

flows (AI prompts/templates)
├─ id, bot_id, name, description
├─ system_prompt (the actual AI instruction)
├─ agentic_mode (bool), max_tool_calls
├─ kb_id (connected knowledge base)
├─ created_at, updated_at

conversations
├─ id, bot_id, external_customer_id (LINE/FB ID)
├─ channel_type, status
├─ memory_notes (JSON), tags (JSON)
├─ created_at, updated_at

messages
├─ id, conversation_id
├─ sender (user/bot), content, type
├─ embeddings (vector) - for semantic search
├─ created_at

documents (KB)
├─ id, kb_id, filename, content
├─ embeddings (vector) - stored as VECTOR(1536)
├─ chunk_index (for large docs)
├─ created_at

customer_profiles
├─ id, external_id (LINE/FB ID)
├─ name, phone, email
├─ interaction_count, last_interaction
├─ metadata (JSON) - custom fields
├─ created_at, updated_at

settings
├─ id, bot_id
├─ daily_message_limit, per_user_limit
├─ hitl_enabled, response_hours (JSON)
├─ created_at, updated_at
```

**Vector Operations**:
```sql
-- Find similar documents (semantic search)
SELECT * FROM documents
WHERE kb_id = 1
ORDER BY embedding <-> query_embedding
LIMIT 5;

-- Create HNSW index for fast search
CREATE INDEX documents_embedding_idx
ON documents USING hnsw (embedding vector_cosine_ops);
```

---

## 🚀 Deployment Architecture

### Development Environment
```
localhost:8000 (Laravel API)
├─ Vite dev server (React hot reload)
├─ Local PostgreSQL (or Neon staging branch)
├─ Redis (local or Docker)
└─ ngrok (for testing webhooks)
```

### Staging Environment (Railway/Render)
```
api.staging.yourdomain.com (Laravel)
├─ PostgreSQL (Neon staging branch)
├─ Redis managed service
├─ Same config as production
└─ For testing before production
```

### Production Environment
```
api.yourdomain.com (Laravel API)
├─ PostgreSQL (Neon production)
├─ Redis managed (Upstash or similar)
├─ Static CDN (Cloudflare)
├─ Docker containers
├─ Auto-scaling (if using AWS)
└─ Monitoring & alerts
```

### CI/CD Pipeline
```
GitHub → Push → GitHub Actions
         ├─ Run Laravel tests (Pest)
         ├─ Run React tests (Vitest)
         ├─ Build React (Vite)
         ├─ Deploy to staging
         └─ Manual approval → Deploy to production
```

---

## 💡 MCP Servers & Claude Code Skills to Use

### Available MCP Servers (Pre-installed)

1. **GitHub MCP** ✅
   - Create/manage issues
   - Analyze PRs
   - Monitor workflows
   - **Usage**: Create issues from this plan, track progress

2. **Playwright MCP** ✅
   - E2E testing React UI
   - Browser automation
   - **Usage**: Test React component interactions

3. **Context7 MCP** ✅
   - Library documentation (React, Laravel, Tailwind)
   - **Usage**: Get up-to-date docs while coding

4. **Greptile MCP** ✅
   - Codebase search & analysis
   - PR review
   - **Usage**: Understand codebase patterns

### Recommended MCP Servers (to install)

```bash
# Laravel Boost - HIGHEST PRIORITY
composer require laravel/boost
php artisan boost:install

# PostgreSQL Database MCP
npm install -g @crystaldba/postgres-mcp

# Optional: Database for SQL queries
npx -y @dbhub/mcp
```

**Laravel Boost provides**:
- Search Laravel docs (17,000+ chunks)
- Execute PHP code in app context
- Query database schema
- View error logs
- List routes, check migrations
- Run Tinker commands

### Claude Code Skills to Use

1. **feature-dev** ✅ (Already available)
   - For each major feature: Use `/feature-dev` to analyze and plan architecture
   - Agents: code-explorer, code-architect, code-reviewer

2. **code-review** ✅
   - After writing code: Use `/code-review` for quality check
   - Checks against project standards

3. **pr-review-toolkit** ✅
   - Before creating PR: Run `/pr-review-toolkit:review-pr --aspects all`
   - Multi-agent comprehensive review

4. **commit-commands** ✅
   - Use `/commit` for structured commit messages
   - Use `/commit-push-pr` to create PRs automatically

5. **frontend-design** ✅
   - When building React components: This skill auto-activates
   - Creates polished, production-grade UI

### Workflow Integration Example

```bash
# Feature 1: Knowledge Base Upload

# 1. Plan the architecture
/feature-dev
→ Analyzes codebase, creates detailed plan

# 2. Create GitHub issue
"Create issue: Implement KB document upload feature"
→ GitHub MCP creates issue #42

# 3. Develop backend (Laravel)
"Create API endpoint for uploading documents"
→ Use Laravel Boost for schema help
→ Generates embeddings with OpenAI SDK

# 4. Develop frontend (React)
"Create upload UI component with progress tracking"
→ frontend-design skill creates polished UI
→ shadcn/ui + Tailwind

# 5. Test
"Use Playwright to test upload flow"
→ Playwright MCP tests the UI interaction

# 6. Code review
/code-review
→ Checks code quality, Laravel/React best practices

# 7. Create PR
/commit-push-pr
→ Creates formatted commit + opens PR + links to issue #42

# 8. Deploy
"Deploy to staging and test"
```

---

## 📝 GitHub Issues Structure

Issues will be organized by phase and epic:

### Phase 1: MVP Foundation
- **Epic 1.1**: Backend Setup (3-4 issues)
- **Epic 1.2**: Frontend Setup (2-3 issues)
- **Epic 1.3**: Core Chat (3-4 issues)

### Phase 2: Core Features
- **Epic 2.1**: Knowledge Base (3-4 issues)
- **Epic 2.2**: Bot Configuration (2-3 issues)
- **Epic 2.3**: Chat Management (3-4 issues)
- **Epic 2.4**: Subscription System (2-3 issues)

### Phase 3: Advanced Features
- **Epic 3.1**: Agentic AI (2-3 issues)
- **Epic 3.2**: Additional Channels (2-3 issues)
- **Epic 3.3**: Advanced Features (3-4 issues)
- **Epic 3.4**: Deployment (2-3 issues)

**Total Issues**: ~35-45 issues across 3 phases

---

## 🎓 Learning Resources & Documentation

### Must-Read Documentation
1. **Laravel**:
   - [Laravel Broadcasting (Reverb)](https://laravel.com/docs/12.x/broadcasting)
   - [Laravel Sanctum](https://laravel.com/docs/12.x/sanctum)
   - [Queue System](https://laravel.com/docs/12.x/queues)

2. **React**:
   - [React 19 Features](https://react.dev/blog/2025/10/01/react-19-2)
   - [TanStack Query](https://tanstack.com/query/latest)
   - [Zustand](https://github.com/pmndrs/zustand)

3. **Database**:
   - [Neon pgvector Guide](https://neon.com/docs/extensions/pgvector)
   - [pgvector Similarity Search](https://supabase.com/docs/guides/database/extensions/pgvector)

4. **External APIs**:
   - [OpenAI API Reference](https://platform.openai.com/docs/api-reference)
   - [LINE Messaging API](https://developers.line.biz/en/reference/messaging-api/)
   - [Facebook Graph API](https://developers.facebook.com/docs/graph-api)

---

## ✅ Success Criteria

### Phase 1 Success
- ✅ User can register and login
- ✅ User can create a bot and connect to LINE OA
- ✅ Bot receives messages and responds via OpenAI
- ✅ Messages stored in database
- ✅ Chat emulator works in real-time

### Phase 2 Success
- ✅ User can upload and manage knowledge base
- ✅ Bot uses KB for smarter responses
- ✅ User can configure bot settings
- ✅ Support multiple flows/templates
- ✅ Subscription/pricing system works
- ✅ Chat management interface complete

### Phase 3 Success
- ✅ Agentic mode working (multi-step reasoning)
- ✅ Facebook Messenger integration
- ✅ All advanced features (lead recovery, screening, etc.)
- ✅ Production deployment on Railway/Render
- ✅ CI/CD pipeline automated
- ✅ Complete documentation

---

## 📞 Questions & Clarifications Needed

Before starting development, please confirm:

1. **Payment Processing**:
   - Use Stripe for international?
   - Use 2C2P for Thai market?
   - Or implement later (subscription mock for MVP)?

2. **AI Models**:
   - Start with OpenAI (GPT-4o)?
   - Support OpenRouter from day 1?
   - Budget for embeddings?

3. **Messaging Channels Priority**:
   - LINE OA first, then Facebook?
   - Or build both simultaneously?

4. **Timeline Flexibility**:
   - Strict 12-week deadline?
   - Can extend if needed?
   - Budget for full-time development vs part-time?

5. **Hosting Preference**:
   - Railway for MVP?
   - AWS later?
   - Self-hosted infrastructure?

---

## 📦 Project Structure

```
bot-fb/
├── backend/                 # Laravel application
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   ├── Requests/
│   │   │   └── Resources/
│   │   ├── Models/
│   │   ├── Services/
│   │   └── Jobs/
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/
│   ├── routes/
│   │   └── api.php
│   ├── tests/
│   └── .env.example
│
├── frontend/                # React application
│   ├── src/
│   │   ├── components/
│   │   ├── pages/
│   │   ├── hooks/
│   │   ├── services/
│   │   ├── store/
│   │   └── App.jsx
│   ├── tests/
│   └── vite.config.js
│
├── docker/
│   ├── nginx/
│   ├── php/
│   └── docker-compose.yml
│
├── .github/
│   └── workflows/
│       ├── laravel-tests.yml
│       ├── react-tests.yml
│       ├── deploy-staging.yml
│       └── deploy-production.yml
│
├── DEVELOPMENT_PLAN.md      # This file
├── CLAUDE.md                # Claude Code guidelines
└── README.md
```

---

## 🎉 Next Steps

1. **Clarify questions** (see section above)
2. **Create GitHub Issues** (based on this plan)
3. **Setup development environment**:
   ```bash
   git clone https://github.com/JaoChai/bot-fb.git
   cd bot-fb
   # Follow setup instructions in README
   ```
4. **Start Phase 1: Backend Setup**
5. **Use `/feature-dev` skill for each major feature**
6. **Track progress in GitHub Issues**

---

**Document Version**: 1.0
**Last Updated**: December 23, 2025
**Next Review**: Before Phase 2 begins
