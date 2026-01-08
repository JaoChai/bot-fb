# BotFacebook

## Stack & URLs

| Layer | Technology | URL |
|-------|-----------|-----|
| Frontend | React 19 | https://www.botjao.com |
| Backend | Laravel 12 | https://api.botjao.com |
| Database | PostgreSQL (Neon + pgvector) | - |
| Deploy | Railway | - |
| WebSocket | Reverb | - |

---

## Quick Commands

### Development
```bash
# Backend
cd backend && php artisan serve

# Frontend
npm run dev

# Database
php artisan migrate
```

### Testing
```bash
php artisan test              # All tests
php artisan test --filter Unit        # Unit only
php artisan test --filter Feature     # Feature only
```

### Deployment
```bash
/commit-push-pr              # Auto: commit + push + PR
railway up                   # Manual deploy
```

[→ Full Git workflow](docs/git-flow.md)

---

## Critical Gotchas

| Problem | Solution | Details |
|---------|----------|---------|
| `config('x','')` returns null | `config('x') ?? ''` | [→](docs/gotchas.md#config-returns-null) |
| API response wrapped | Access `response.data` | [→](docs/gotchas.md#api-response-wrapped) |
| Railway serve.json fails | Use Express server | [→](docs/gotchas.md#railway-servejson-fails) |
| N+1 queries | Use eager loading | [→](docs/gotchas.md#n1-query-problem) |
| Race condition | Use DB locks | [→](docs/gotchas.md#race-condition-in-customer-profile-creation) |

[→ All known issues](docs/gotchas.md)

---

## Quick Decision Guide

### What should I do?

| Task | Action | Reference |
|------|--------|-----------|
| New feature (>30min) | `/speckit.specify` | [Speckit](#speckit-workflow) |
| Bug fix | `/mem-search` first | [Decision Trees](docs/decision-trees.md) |
| UI work | `frontend-dev` set | [Agent Sets](docs/agent-sets.md) |
| API work | `backend-dev` set | [Agent Sets](docs/agent-sets.md) |
| Database | `database` set | [Agent Sets](docs/agent-sets.md) |
| Performance issue | `performance` set | [Performance Guide](docs/performance.md) |
| Before commit | `code-review` set | Auto-triggered |

[→ Full decision trees](docs/decision-trees.md)

---

## Agent Sets (15 Available)

| Icon | Set | Use When | Details |
|------|-----|----------|---------|
| 🎨 | frontend-dev | React, UI, components | [→](docs/agent-sets.md#frontend-dev) |
| ⚙️ | backend-dev | Laravel, API, services | [→](docs/agent-sets.md#backend-dev) |
| 🗄️ | database | Migrations, queries, pgvector | [→](docs/agent-sets.md#database) |
| 🔍 | rag-debug | Semantic search, embeddings | [→](docs/agent-sets.md#rag-debug) |
| 🔗 | webhook-debug | LINE, Telegram, WebSocket | [→](docs/agent-sets.md#webhook-debug) |
| ⚡ | performance | Slow queries, optimize | [→](docs/agent-sets.md#performance) |
| 📝 | code-review | Before commit | [→](docs/agent-sets.md#code-review) |
| 🔒 | security | Security audit | [→](docs/agent-sets.md#security) |
| 🌐 | api-design | API consistency | [→](docs/agent-sets.md#api-design) |
| 📱 | ui-test | UI testing | [→](docs/agent-sets.md#ui-test) |
| 🧪 | backend-test | Unit/feature tests | [→](docs/agent-sets.md#backend-test) |
| 🔄 | integration-test | E2E flows | [→](docs/agent-sets.md#integration-test) |
| 🚀 | deployment | Deploy issues | [→](docs/agent-sets.md#deployment) |
| 💬 | prompt-eng | Prompt optimization | [→](docs/agent-sets.md#prompt-eng) |
| 📚 | memory | Search past work | [→](docs/agent-sets.md#memory) |

[→ Full agent sets guide](docs/agent-sets.md)

---

## Code Change Rules

### Minimal Change Principle

| Rule | Description |
|------|-------------|
| แก้เฉพาะจุด | ห้ามแก้โค้ดที่ไม่เกี่ยวข้อง |
| ห้าม refactor | แยกเป็น task ใหม่ |
| ห้ามเพิ่ม feature | Focus เฉพาะที่ได้รับมอบหมาย |
| ตรวจ git diff | ก่อน commit ต้องเช็คว่าเกี่ยวข้องทั้งหมด |

### Before Every Commit
- [ ] ทุกไฟล์เกี่ยวข้องกับ task?
- [ ] ไม่มี refactor/cleanup ไม่เกี่ยวข้อง?
- [ ] ไม่มี feature ใหม่ที่ไม่ได้ขอ?

[→ Detailed rules & examples](docs/code-change-rules.md)

---

## Speckit Workflow

### เมื่อไหร่ต้องใช้ Speckit?
- งาน >30 นาที
- แก้ไข >3 ไฟล์
- Feature ซับซ้อน

### Workflow
```bash
1. /speckit.specify "[feature description]"
   → สร้าง branch + spec.md

2. /speckit.clarify  (ถ้า requirements ไม่ชัด)

3. /speckit.plan
   → สร้าง technical plan

4. /speckit.tasks
   → สร้าง task breakdown

5. /speckit.implement
   → ลงมือทำ
```

### ไม่ต้องใช้ Speckit เมื่อ:
- Bug fix เล็กๆ
- งานที่แก้ไฟล์เดียว
- งาน <30 นาที

---

## Git Flow

### Branch Naming
```bash
feature/xxx   # New features
fix/xxx       # Bug fixes
chore/xxx     # Maintenance
```

### Workflow
```bash
1. git checkout -b feature/xxx
2. # Work & commit often
3. /commit-push-pr
```

### Conventional Commits
```bash
feat: add user profile page
fix: resolve race condition
chore: update dependencies
refactor: extract service layer
docs: update API guide
test: add unit tests
```

[→ Full Git workflow](docs/git-flow.md)

---

## API Standards

### Response Format
```json
{
  "data": { },
  "meta": { "timestamp": "..." },
  "errors": []
}
```

### Status Codes
```
200 OK | 201 Created | 400 Bad Request
401 Unauthorized | 404 Not Found | 422 Validation
429 Rate Limit | 500 Server Error
```

### RESTful Patterns
```
GET    /api/v1/bots
POST   /api/v1/bots
GET    /api/v1/bots/{id}
PUT    /api/v1/bots/{id}
DELETE /api/v1/bots/{id}
```

[→ Complete API guide](docs/api-standards.md)

---

## Testing Strategy

| Type | Command | Target |
|------|---------|--------|
| Unit | `php artisan test --filter Unit` | Services 80%+ |
| Feature | `php artisan test --filter Feature` | Controllers 60%+ |
| E2E | `integration-test` set | Critical flows |

[→ Full testing guide](docs/testing.md)

---

## Security Checklist

| Category | Status |
|----------|--------|
| Input validation | ✅ FormRequest |
| Auth/Authorization | ✅ JWT + Policies |
| XSS Prevention | ✅ Auto-escaped |
| CSRF Protection | ✅ Laravel default |
| Rate Limiting | ✅ 60 req/min |

[→ Security best practices](docs/security.md)

---

## Performance Targets

| Metric | Target | Monitor |
|--------|--------|---------|
| API response | < 500ms | Sentry |
| AI evaluation | < 1.5s | Logs |
| Page load | < 2s | Lighthouse |
| Database query | < 100ms | Neon |

[→ Optimization guide](docs/performance.md)

---

## Monitoring & Debugging

| Service | Purpose | Quick Link |
|---------|---------|------------|
| Sentry | Error tracking | [Dashboard](https://sentry.io/...) |
| Railway | Deployment logs | [Console](https://railway.app/...) |
| Neon | Database metrics | [Dashboard](https://neon.tech/...) |

**Debug workflow:**
1. Check Sentry for errors
2. `/mem-search` for similar issues
3. Use appropriate debug set
4. Check service logs

[→ Debugging guide](docs/debugging.md)

---

## Reference Documentation

### Core Guides
| Document | Description |
|----------|-------------|
| [Agent Sets](docs/agent-sets.md) | All 15 agent sets + usage examples |
| [Decision Trees](docs/decision-trees.md) | When to use what tool/agent |
| [Git Workflow](docs/git-flow.md) | Complete Git + GitHub flow |
| [Code Change Rules](docs/code-change-rules.md) | Minimal change principle |

### Technical Guides
| Document | Description |
|----------|-------------|
| [API Standards](docs/api-standards.md) | RESTful API design guide |
| [Testing](docs/testing.md) | Unit/Feature/E2E testing |
| [Security](docs/security.md) | Security best practices |
| [Performance](docs/performance.md) | Optimization techniques |

### Troubleshooting
| Document | Description |
|----------|-------------|
| [Gotchas](docs/gotchas.md) | Known issues + solutions |
| [Debugging](docs/debugging.md) | Debug workflow + tools |

---

## AI Evaluation System (003-refactor-ai-evaluation)

### Architecture

**Key Services:**
- `ModelTierSelector` - Smart model selection (budget/standard/premium tiers)
- `LLMJudgeService` - Evaluation engine with automatic fallback
- `UnifiedCheckService` - Combined Second AI checks (Fact/Policy/Personality)

**Cost Optimization:**
- Budget tier: Free models (Gemini Flash) for simple metrics
- Standard tier: Cheap models (GPT-4o-mini) for moderate complexity
- Premium tier: Expensive models (Claude Sonnet) for complex analysis
- **Result**: 57% cost reduction vs all-premium baseline

**Performance Improvements:**
- Unified Second AI: 1 API call instead of 6-9 calls
- Latency: 3-6s → ~1.5s (50% reduction)
- Graceful fallback when primary model fails

**Configuration:**
```bash
# Force premium tier for all evaluations
EVALUATION_FORCE_PREMIUM=false

# Override tier for specific metrics
EVALUATION_TIER_OVERRIDE_FAITHFULNESS=premium
```

### Testing Commands
```bash
# Test unified Second AI mode
php artisan test:unified-mode

# Test model tier system
php artisan test:model-tiers --test-cases=40

# Run all evaluation tests
php artisan test tests/Feature/Evaluation
php artisan test tests/Unit/Evaluation
```

---

## Active Technologies

- **Backend:** PHP 8.2, Laravel 12, OpenRouter API
- **Frontend:** TypeScript 5.x, React 19, TanStack Query
- **Database:** PostgreSQL (Neon) with pgvector
- **Deploy:** Railway with Reverb (WebSocket)

---

**Last Updated:** 2026-01-08

**Documentation Version:** 2.0 (Refactored)
