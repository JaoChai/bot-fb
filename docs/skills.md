# Agent Skills Reference

17 skills available - auto-triggered from context or use `/skill-name`

---

## Quick Reference

| Category | Skill | Trigger Keywords |
|----------|-------|------------------|
| Development | `/backend-dev` | Laravel, API, services, jobs |
| Development | `/frontend-dev` | React, components, hooks, state |
| Development | `/refactor` | technical debt, code structure |
| Design | `/ui-ux-pro-max` | UI design, styles, colors, shadcn |
| Data | `/database-ops` | migrations, queries, vectors |
| Data | `/rag-debug` | search not working, embeddings |
| Quality | `/refactor` (dead-code) | dead code, unused, cleanup, knip |
| Quality | `/code-review` | before commit, security audit |
| Quality | `/testing` | write tests, PHPUnit, Playwright |
| Operations | `/deployment` | deploy, production issues |
| Operations | `/performance` | slow queries, optimization |
| Operations | `/monitoring` | error tracking, Sentry, logs |
| Security | `/auth-security` | auth, API keys, credentials |
| Debug | `/webhook-debug` | bot not responding, webhook, race condition, deadlock, locks, concurrency |
| Debug | `/agentic-debug` | agent loop, ReAct, tool calls, smart routing, Second AI |
| AI | `/prompt-eng` | prompt optimization, injection detection |
| AI | `/payment-orders` | payment, order, Flex, VIP, bank transfer, vip-check, promote VIP |

---

## Skill Details

### Development

#### `/backend-dev`
Laravel 12 specialist for API development.

**Use when:** Creating/modifying API endpoints, services, jobs, events

**Covers:**
- Controllers, Services, FormRequests
- API Resources, Queue Jobs
- Events & Broadcasting (Reverb)
- Laravel 12 + PHP 8.4 patterns

---

#### `/frontend-dev`
React 19 specialist for frontend implementation.

**Use when:** Creating/modifying React components, pages, hooks

**Covers:**
- React 19 components & hooks
- State management (Zustand)
- Data fetching (React Query v5)
- Styling (Tailwind v4)

---

#### `/refactor`
Code refactoring specialist.

**Use when:** Cleaning up technical debt, improving code structure

**Covers:**
- Extract Method/Service patterns
- Component decomposition
- Query optimization refactoring
- Laravel 12 + React 19 patterns

---

### Design

#### `/ui-ux-pro-max`
UI/UX design intelligence.

**Use when:** Designing websites, dashboards, any UI component

**Covers:**
- 50 design styles
- 21 color palettes
- 50 font pairings
- shadcn/ui components
- Accessibility & responsive design

---

### Data

#### `/database-ops`
Database operations specialist for PostgreSQL/Neon.

**Use when:** Creating migrations, optimizing queries, vector operations

**Covers:**
- PostgreSQL + Neon
- pgvector extension
- Schema design & migrations
- Query optimization
- Semantic search setup

---

#### `/rag-debug`
RAG pipeline debugger for semantic search.

**Use when:** Search not finding results, embedding problems

**Covers:**
- Embedding diagnostics
- Reranker tuning
- Thai language NLP issues
- Context retrieval problems

---

### Quality

#### `/code-review`
Comprehensive code reviewer.

**Use when:** Before committing code, security audits

**Covers:**
- OWASP Top 10 security
- RESTful API conventions
- Best practices review
- Performance checks

---

#### `/testing`
Full-stack testing specialist.

**Use when:** Writing/running tests

**Covers:**
- PHPUnit (unit & feature tests)
- Playwright (E2E)
- Responsive UI testing
- Accessibility testing

---

### Operations

#### `/deployment`
Railway deployment specialist.

**Use when:** Deploying, debugging production issues

**Covers:**
- Railway deployments
- Environment variables
- Log analysis
- Rollbacks & troubleshooting

---

#### `/performance`
Performance optimization specialist.

**Use when:** App is slow, queries take too long

**Covers:**
- N+1 query detection
- Database optimization
- Bundle size analysis
- Core Web Vitals

---

#### `/monitoring`
Observability and monitoring specialist.

**Use when:** Error tracking, setting up alerts

**Covers:**
- Sentry error tracking
- Railway logs
- Health checks
- Alerting setup

---

### Security

#### `/auth-security`
Authentication and security specialist.

**Use when:** Implementing auth, managing API keys

**Covers:**
- Laravel Sanctum
- OAuth flows
- Rate limiting
- OWASP security practices

---

### Debug

#### `/webhook-debug`
Webhook, messaging, and concurrency debugger.

**Use when:** Bot not responding, webhook failures, duplicate messages, race conditions

**Covers:**
- LINE/Telegram/Facebook webhook debugging
- ProcessLINEWebhook pipeline (dedup, circuit breaker, transaction splitting)
- Message aggregation pipeline (SmartAggregation, timer reset)
- Vision & sticker routing
- Concurrency patterns (8 patterns: locks, dedup, circuit breaker, etc.)
- WebSocket (Reverb) issues
- Queue job failures

---

### AI

#### `/prompt-eng`
Prompt engineering specialist.

**Use when:** Creating/improving AI prompts

**Covers:**
- System prompt design
- A/B testing prompts
- Prompt injection defense
- Response quality optimization

---

#### `/agentic-debug`
Agent loop, smart routing, and Second AI debugger.

**Use when:** Agent loop stuck, tool calls failing, smart routing choosing wrong mode, Second AI issues

**Covers:**
- AgentLoopService ReAct loop debugging
- Smart routing decision tree analysis
- Tool execution failures (search_kb, calculate, think, etc.)
- Safety controls (timeout, cost, HITL)
- Second AI pipeline (unified vs sequential mode, skip patterns)
- Timeout layering (pipeline 25s, HTTP 15s, agent 120s, SSE 30s)
- StreamController SSE architecture
- Message truncation debugging

---

#### `/payment-orders`
Payment flow, order management, and VIP promotion specialist.

**Use when:** Payment Flex detection issues, order tracking, VIP pricing, bank transfer verification, VIP promotion

**Covers:**
- 6-type payment flow (Payment→SupportDelay→Terms→Verify→Confirm + VIP variants)
- PaymentFlexService detection priority (6 types)
- SupportDelay Flex (VIP gold / normal orange)
- VIP color system (5 Flex types with normal/VIP colors)
- VIP promotion workflow (via `/vip-check` trigger)
- LINE Flex message builder
- Order deduplication
- Bank transfer verification
- Markdown stripping for regex detection

---

## Auto-Trigger Examples

```
"สร้าง React component" → /frontend-dev
"ออกแบบ landing page"   → /ui-ux-pro-max
"สร้าง migration"        → /database-ops
"ทำไม search ไม่เจอ"     → /rag-debug
"review ก่อน commit"     → /code-review
"deploy ไม่ผ่าน"         → /deployment
"เช็ค error ใน Sentry"   → /monitoring
"ทำ login flow"          → /auth-security
"webhook LINE พัง"       → /webhook-debug
"ปรับ system prompt"     → /prompt-eng
"หา dead code"           → /refactor
"unused imports"         → /refactor
"agent loop ค้าง"        → /agentic-debug
"smart routing เลือกผิด"  → /agentic-debug
"ข้อความซ้ำ duplicate"    → /webhook-debug
"race condition"         → /webhook-debug
"payment Flex ไม่ detect" → /payment-orders
"order tracking"         → /payment-orders
"promote VIP"            → /payment-orders
"vip-check"              → /payment-orders
"Second AI ไม่ทำงาน"     → /agentic-debug
```

---

## Decision Matrix

| Task Type | Primary Skill | Secondary |
|-----------|--------------|-----------|
| New feature (>30min) | `/speckit.specify` | - |
| Bug fix | Search memory | Relevant skill |
| UI implementation | `/frontend-dev` | `/ui-ux-pro-max` |
| API work | `/backend-dev` | - |
| Database changes | `/database-ops` | - |
| Search/RAG issues | `/rag-debug` | - |
| Before commit | `/code-review` | - |
| Write tests | `/testing` | - |
| Performance issues | `/performance` | - |
| Deploy problems | `/deployment` | `/monitoring` |
| Auth/Security | `/auth-security` | - |
| Bot not responding | `/webhook-debug` | - |
| Prompt optimization | `/prompt-eng` | - |
| Code cleanup | `/refactor` | - |
| Agent loop issues | `/agentic-debug` | `/webhook-debug` |
| Duplicate/race conditions | `/webhook-debug` | - |
| Payment/order/VIP issues | `/payment-orders` | - |
