# BotFacebook

## Stack

| Layer | Technology | URL |
|-------|-----------|-----|
| Frontend | React 19 + TypeScript + Tailwind v4 | https://www.botjao.com |
| Backend | Laravel 12 + PHP 8.2 | https://api.botjao.com |
| Database | PostgreSQL (Neon) + pgvector | - |
| Deploy | Railway | - |
| Real-time | Reverb (WebSocket) | - |
| AI | OpenRouter API | - |

---

## Quick Commands

```bash
# Development
cd backend && php artisan serve    # Backend
cd frontend && npm run dev         # Frontend

# Testing
php artisan test                   # All tests
php artisan test --filter Unit     # Unit only

# Deploy
railway up                         # Manual deploy
```

---

## Agent Skills (12 Available)

Skills จะ auto-trigger จาก context หรือเรียกด้วย `/skill-name`

### 🔵 Development
| Skill | Use When | Key Features |
|-------|----------|--------------|
| `/backend-dev` | Laravel API, services, jobs | Laravel 12, FormRequest, API Resources |
| `/frontend-dev` | React components, hooks, styling | React 19, Tailwind v4, React Query v5 |
| `/refactor` | Technical debt, code structure | Extract Method/Service, component decomposition |

### 🟢 Design
| Skill | Use When | Key Features |
|-------|----------|--------------|
| `/ui-ux-pro-max` | UI design, styles, colors, fonts | 50 styles, 21 palettes, 50 font pairings |

### 🟣 Data
| Skill | Use When | Key Features |
|-------|----------|--------------|
| `/database-ops` | Migrations, queries, vectors | Neon, pgvector, query optimization |
| `/rag-debug` | Search not working, embeddings | Semantic search, reranker, Thai NLP |

### 🔴 Quality
| Skill | Use When | Key Features |
|-------|----------|--------------|
| `/code-review` | Before commit, security audit | OWASP Top 10, API design, best practices |
| `/testing` | Write/run tests | PHPUnit, Playwright, E2E, UI testing |

### 🟠 Operations
| Skill | Use When | Key Features |
|-------|----------|--------------|
| `/deployment` | Deploy, production issues | Railway, logs, troubleshooting |
| `/performance` | Slow queries, optimization | N+1 detection, bundle size, Core Web Vitals |

### 🟡 Debug
| Skill | Use When | Key Features |
|-------|----------|--------------|
| `/webhook-debug` | Bot not responding | LINE, Telegram, WebSocket, job failures |

### ⚪ AI
| Skill | Use When | Key Features |
|-------|----------|--------------|
| `/prompt-eng` | AI prompt optimization | Prompt design, A/B testing, injection defense |

---

## Slash Commands

### 📋 Speckit - จัดการงานใหญ่
| Command | Use When |
|---------|----------|
| `/speckit.specify` | สร้าง feature spec จาก description |
| `/speckit.clarify` | ถามคำถามเพิ่มเติมเพื่อความชัดเจน |
| `/speckit.plan` | วาง technical implementation plan |
| `/speckit.tasks` | แบ่ง tasks จาก plan |
| `/speckit.implement` | ลงมือทำตาม tasks |
| `/speckit.checklist` | สร้าง checklist สำหรับ feature |
| `/speckit.analyze` | วิเคราะห์ consistency ข้าม artifacts |
| `/speckit.taskstoissues` | แปลง tasks เป็น GitHub issues |
| `/speckit.constitution` | สร้าง/อัพเดท project constitution |

### 🔧 Utility
| Command | Use When |
|---------|----------|
| `/review-learnings` | Review patterns และสร้าง rules ใหม่ |

---

## Auto-Trigger Examples

```
"สร้าง React component" → 🔵 /frontend-dev
"ออกแบบ landing page"   → 🟢 /ui-ux-pro-max
"สร้าง migration"        → 🟣 /database-ops
"ทำไม search ไม่เจอ"     → 🟣 /rag-debug
"review ก่อน commit"     → 🔴 /code-review
"deploy ไม่ผ่าน"         → 🟠 /deployment
"webhook LINE พัง"       → 🟡 /webhook-debug
"ปรับ system prompt"     → ⚪ /prompt-eng
"refactor code"          → 🔵 /refactor
```

---

## Decision Guide

| Task | Action |
|------|--------|
| New feature (>30min) | 📋 `/speckit.specify` |
| Bug fix | Search memory first, then fix |
| UI implementation | 🔵 `/frontend-dev` |
| UI/UX design | 🟢 `/ui-ux-pro-max` |
| API work | 🔵 `/backend-dev` |
| Database | 🟣 `/database-ops` |
| Search/RAG issue | 🟣 `/rag-debug` |
| Before commit | 🔴 `/code-review` |
| Write tests | 🔴 `/testing` |
| Performance | 🟠 `/performance` |
| Deploy issue | 🟠 `/deployment` |
| Bot not responding | 🟡 `/webhook-debug` |
| Prompt optimization | ⚪ `/prompt-eng` |
| Code refactoring | 🔄 `/refactor` |

---

## Critical Gotchas

| Problem | Solution |
|---------|----------|
| `config('x','')` returns null | `config('x') ?? ''` |
| API response wrapped | Access `response.data` |
| N+1 queries | Use `->with()` eager loading |
| Race condition | Use DB locks |

[→ All gotchas](docs/gotchas.md)

---

## Code Rules

### ⚠️ MUST: Use Agent Skills When Implementing
หลังจาก plan mode หรือเมื่อลงมือทำงาน **ต้องเรียก agent skills เสมอ** (ดู Decision Guide ด้านบน)

**ห้ามแก้โค้ดโดยไม่เรียก agent** - ถ้าแก้ทั้ง backend + frontend ต้องเรียกทั้ง 2 agents

### Minimal Change Principle
- แก้เฉพาะจุดที่เกี่ยวข้อง
- ห้าม refactor โค้ดอื่น
- ห้ามเพิ่ม feature ที่ไม่ได้ขอ

### Before Commit
- [ ] ทุกไฟล์เกี่ยวข้องกับ task?
- [ ] ไม่มี console.log/dd() ค้าง?
- [ ] ไม่มี hardcoded values?

---

## Speckit Workflow

ใช้เมื่องาน >30 นาที หรือแก้ >3 ไฟล์

```bash
/speckit.specify "[description]"  # สร้าง spec
/speckit.plan                     # วาง technical plan
/speckit.tasks                    # แบ่ง tasks
/speckit.implement                # ลงมือทำ + ⚠️ ต้องเรียก agent skills ด้วย
```

**⚠️ ระหว่าง `/speckit.implement`**: ต้องเรียก agent skills ตาม Decision Guide
- Task แก้ backend → เรียก `/backend-dev`
- Task แก้ frontend → เรียก `/frontend-dev`
- ก่อน commit → เรียก `/code-review`

---

## Git Flow

```bash
# Branch
feature/xxx | fix/xxx | chore/xxx

# Commits
feat: add user profile
fix: resolve race condition
chore: update dependencies

# Workflow
git checkout -b feature/xxx
# work...
/commit-push-pr
```

---

## API Standards

```json
// Response format
{
  "data": { },
  "meta": { "timestamp": "..." },
  "errors": []
}
```

```
// Status codes
200 OK | 201 Created | 400 Bad Request
401 Unauthorized | 404 Not Found | 422 Validation
```

```
// RESTful routes
GET    /api/v1/bots
POST   /api/v1/bots
GET    /api/v1/bots/{id}
PUT    /api/v1/bots/{id}
DELETE /api/v1/bots/{id}
```

---

## Testing

| Type | Command | Coverage |
|------|---------|----------|
| Unit | `php artisan test --filter Unit` | Services 80%+ |
| Feature | `php artisan test --filter Feature` | Controllers 60%+ |
| E2E | `/testing` skill | Critical flows |

---

## Performance Targets

| Metric | Target |
|--------|--------|
| API response | < 500ms |
| AI evaluation | < 1.5s |
| Page load | < 2s |
| DB query | < 100ms |

---

## Monitoring

| Service | Purpose |
|---------|---------|
| Sentry | Error tracking |
| Railway | Deploy logs |
| Neon | DB metrics |

**Debug workflow:**
1. Check Sentry for errors
2. Use appropriate `/skill-name`
3. Check service logs

---

## AI Evaluation System

**Services:**
- `ModelTierSelector` - Budget/Standard/Premium tier selection
- `LLMJudgeService` - Evaluation with fallback
- `UnifiedCheckService` - Combined Second AI checks

**Cost:** 57% reduction with tiered models

```bash
# Test commands
php artisan test:unified-mode
php artisan test:model-tiers --test-cases=40
```

---

**Last Updated:** 2026-01-17
