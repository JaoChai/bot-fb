# BotFacebook

AI-powered chatbot platform for LINE and Telegram with RAG-based knowledge retrieval.

## Stack

| Layer | Technology |
|-------|-----------|
| Frontend | React 19 + TypeScript + Tailwind v4 |
| Backend | Laravel 12 + PHP 8.2 |
| Database | PostgreSQL (Neon) + pgvector |
| Deploy | Railway |
| Real-time | Reverb (WebSocket) |
| AI | OpenRouter API |

## Project Structure

```
backend/
├── app/Services/       # 38+ services (business logic)
├── app/Models/         # 35 models
├── app/Jobs/           # 13 async jobs
├── config/             # llm-models.php, rag.php, tools.php
└── routes/api.php

frontend/
├── src/components/     # UI components
├── src/pages/          # 17 route pages
├── src/hooks/          # 21 custom hooks
├── src/stores/         # Zustand (auth, chat, ui)
└── src/lib/api.ts      # Axios client
```

## Commands

```bash
# Dev
cd backend && php artisan serve
cd frontend && npm run dev

# Test
php artisan test
php artisan test --filter Unit

# Deploy
railway up
```

## Skills (14 available)

Auto-triggered from context or use `/skill-name`. → [Full reference](docs/skills.md)

| Task | Skill |
|------|-------|
| Laravel API, services | `/backend-dev` |
| React components | `/frontend-dev` |
| UI design, shadcn | `/ui-ux-pro-max` |
| Database, migrations | `/database-ops` |
| Search/RAG issues | `/rag-debug` |
| Before commit | `/code-review` |
| Write tests | `/testing` |
| Deploy issues | `/deployment` |
| Performance | `/performance` |
| Error tracking | `/monitoring` |
| Auth, API keys | `/auth-security` |
| Bot not responding | `/webhook-debug` |
| Prompt optimization | `/prompt-eng` |
| Code cleanup | `/refactor` |

## Critical Gotchas

| Problem | Solution |
|---------|----------|
| `config('x','')` returns null | `config('x') ?? ''` |
| API response wrapped | Access `response.data` |
| N+1 queries | Use `->with()` eager loading |
| Race condition | Use DB locks |

→ [All gotchas](docs/gotchas.md)

## Code Rules

**MUST: Use Agent Skills** - ห้ามแก้โค้ดโดยไม่เรียก skill

**Minimal Change Principle:**
- แก้เฉพาะจุดที่เกี่ยวข้อง
- ห้าม refactor โค้ดอื่น
- ห้ามเพิ่ม feature ที่ไม่ได้ขอ

**Before Commit:**
- [ ] ทุกไฟล์เกี่ยวข้องกับ task?
- [ ] ไม่มี console.log/dd() ค้าง?
- [ ] ไม่มี hardcoded values?

## Speckit (งานใหญ่ >30min)

```bash
/speckit.specify "[description]"  # สร้าง spec
/speckit.plan                     # วาง plan
/speckit.tasks                    # แบ่ง tasks
/speckit.implement                # ลงมือทำ (ต้องเรียก skills ด้วย)
```

## Git Flow

```bash
# Branch: feature/xxx | fix/xxx | chore/xxx
# Commits: feat: | fix: | chore:
git checkout -b feature/xxx && # work... && /commit-push-pr
```

## References

- [Skills Reference](docs/skills.md)
- [Services Reference](docs/services.md)
- [AI System](docs/ai-system.md)
- [Gotchas](docs/gotchas.md)
- [Testing](docs/testing.md)
- [Security](docs/security.md)
