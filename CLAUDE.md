# BotFacebook

AI-powered chatbot platform for LINE and Telegram with RAG-based knowledge retrieval.

## Stack

| Layer | Technology |
|-------|-----------|
| Frontend | React 19 + TypeScript + Tailwind v4 |
| Backend | Laravel 12 + PHP 8.4 |
| Database | PostgreSQL (Neon) + pgvector |
| Deploy | Railway |
| Real-time | Reverb (WebSocket) |
| AI | OpenRouter API |

## Working Directories

```
bot-fb/
├── backend/    ← cd here for php artisan, composer
└── frontend/   ← cd here for npm, npx
```

## Common Commands

```bash
# Backend (run from backend/)
cd backend
php artisan test                        # All tests
php artisan test --filter=Unit          # Unit tests only
php artisan test --filter=PaymentFlex   # Specific test
php artisan queue:failed                # Check failed jobs
php artisan tinker                      # REPL

# Frontend (run from frontend/)
cd frontend
npm run dev                             # Dev server
npm run build                           # Production build
npm run type-check                      # TypeScript check
npx knip --reporter compact             # Dead code scan
```

## Project Structure

```
backend/
├── app/Services/       # ~37 services (business logic)
├── app/Models/         # ~26 models
├── app/Jobs/           # 7 async jobs
├── config/             # llm-models.php, rag.php, agent-prompts.php
└── routes/api.php

frontend/
├── src/components/     # UI components
├── src/pages/          # 10 pages
├── src/hooks/          # ~23 custom hooks
├── src/stores/         # Zustand (auth, chat, ui, botPreferences, connection)
└── src/lib/api.ts      # Axios client
```

## Core Files

| File | Purpose |
|------|---------|
| `config/llm-models.php` | ~33 AI models with pricing |
| `config/rag.php` | RAG/embedding settings |
| `config/agent-prompts.php` | Agent prompt templates (Thai/English) |
| `routes/api.php` | All API endpoints |
| `app/Services/RAGService.php` | Main AI orchestration |
| `app/Services/OpenRouterService.php` | LLM API client |
| `app/Services/FlowCacheService.php` | Flow config caching (30-min TTL) |

## Skills

Skills auto-triggered from context or use `/skill-name`. → [Full reference](docs/skills.md)

## Critical Gotchas

| Problem | Solution |
|---------|----------|
| `config('x','')` returns null | `config('x') ?? ''` |
| API response wrapped | Access `response.data` |
| N+1 queries | Use `->with()` eager loading |
| Race condition | Use DB locks |
| TypeScript interface ไม่ match Laravel model | Verify `api.ts` matches `$fillable/$casts` |
| Flow ≠ Bot | Flow เป็น central config, ไม่ใช่ Bot |

→ [All gotchas](docs/gotchas.md)

## Code Rules

**Minimal Change Principle:**
- แก้เฉพาะจุดที่เกี่ยวข้อง ห้าม refactor โค้ดอื่น ห้ามเพิ่ม feature ที่ไม่ได้ขอ

**Use skills for non-trivial changes** — งานที่แก้หลายไฟล์หรือต้องรู้ context ของ domain ให้เรียก skill ที่เกี่ยวข้อง งานเล็กๆ (แก้ typo, เปลี่ยน config ค่าเดียว) ทำได้เลย

**Before Commit:**
- [ ] ทุกไฟล์เกี่ยวข้องกับ task?
- [ ] ไม่มี console.log/dd() ค้าง?
- [ ] ไม่มี hardcoded values?

## Skill Priority Rules

**Custom skills มี priority สูงกว่า everything-claude-code (ECC) skills เสมอ** — ใช้ custom skill ก่อน เสริมด้วย ECC ถ้าจำเป็น

**ห้ามใช้ ECC skills สำหรับ stack อื่น** (Go, Spring Boot, ClickHouse, Django, Node.js backend) — project นี้ใช้ Laravel + React เท่านั้น

**Planning:** งานใหญ่ (>30min) ใช้ `speckit.*` / งานกลาง ใช้ `EnterPlanMode`

## Git Flow

```bash
# Branch: feature/xxx | fix/xxx | chore/xxx
# Commits: feat: | fix: | chore:
git checkout -b feature/xxx && # work... && /commit-push-pr
```

## Do Not Touch

- `vendor/`, `node_modules/` - Dependencies
- `*.lock` files - Managed by tools
- `storage/`, `dist/` - Generated files

## References

- [Skills Reference](docs/skills.md)
- [Services Reference](docs/services.md)
- [Gotchas](docs/gotchas.md)
- [Testing](docs/testing.md)
- [Security](docs/security.md)
