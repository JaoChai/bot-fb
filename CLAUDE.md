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
/commit-push-pr                    # Commit + Push + PR
railway up                         # Manual deploy
```

---

## Agent Skills (11 Available)

Skills จะ auto-trigger จาก context หรือเรียกด้วย `/skill-name`

| Skill | Use When | Key Features |
|-------|----------|--------------|
| `/frontend-dev` | React components, UI, styling | React 19, Tailwind v4, React Query v5 |
| `/ui-ux-pro-max` | UI design, styles, colors, fonts | 50 styles, 21 palettes, 50 font pairings |
| `/backend-dev` | Laravel API, services, jobs | Laravel 12, FormRequest, API Resources |
| `/database-ops` | Migrations, queries, vectors | Neon, pgvector, query optimization |
| `/code-review` | Before commit, security audit | OWASP Top 10, API design, code quality |
| `/testing` | Write/run tests | PHPUnit, Playwright, UI testing |
| `/performance` | Slow queries, optimization | N+1 detection, bundle size, Core Web Vitals |
| `/deployment` | Deploy, production issues | Railway, logs, troubleshooting |
| `/rag-debug` | Search not working | Embeddings, reranker, Thai NLP |
| `/webhook-debug` | Bot not responding | LINE, Telegram, WebSocket |
| `/prompt-eng` | AI prompt optimization | Prompt design, A/B testing |

### Auto-Trigger Examples
```
"สร้าง React component" → /frontend-dev
"ออกแบบ landing page"   → /ui-ux-pro-max
"ทำไม search ไม่เจอ"    → /rag-debug
"webhook LINE พัง"      → /webhook-debug
"review ก่อน commit"    → /code-review
```

---

## Decision Guide

| Task | Action |
|------|--------|
| New feature (>30min) | `/speckit.specify` |
| Bug fix | Search memory first, then fix |
| UI implementation | `/frontend-dev` |
| UI/UX design | `/ui-ux-pro-max` |
| API work | `/backend-dev` |
| Database | `/database-ops` |
| Before commit | `/code-review` |
| Performance | `/performance` |

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
หลังจาก plan mode หรือเมื่อลงมือทำงาน **ต้องเรียก agent skills เสมอ**:

| ไฟล์ที่แก้ | Agent ที่ต้องเรียก |
|-----------|-------------------|
| `.php` (Controller, Service, Job) | `/backend-dev` |
| `.tsx`, `.ts` (Component, Hook) | `/frontend-dev` |
| Migration, Query | `/database-ops` |
| ก่อน commit | `/code-review` |

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
/speckit.implement                # ลงมือทำ
```

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

**Last Updated:** 2026-01-14
