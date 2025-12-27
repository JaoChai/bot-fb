# CLAUDE.md - BotFacebook Project Guidelines

## Quick Codes
| Code | Action |
|------|--------|
| `ccc` | Create context issue + compact |
| `nnn` | Smart planning (auto-ccc if needed) |
| `gogogo` | Execute plan step-by-step |
| `lll` | List project status |
| `rrr` | Session retrospective |

## Critical Safety Rules
- **NEVER** use `-f`/`--force` flags
- **NEVER** merge PRs without explicit user permission
- **NEVER** use `rm -rf` - use `rm -i`
- Always wait for user approval before destructive actions

## Debugging Strategy (MANDATORY)

### Before Fixing Any Error:
```
1. curl /api/health → ยืนยัน backend ทำงาน
2. Check logs → railway logs / docker logs
3. Check deployment config → Dockerfile, Procfile, .env
4. ทำทีละขั้น → test → proceed
```

### Common Pitfalls:
| Error ที่เห็น | Root Cause ที่เป็นไปได้ |
|--------------|------------------------|
| CORS error | Backend อาจ down ไม่ใช่ CORS config |
| 502 Bad Gateway | Port binding ผิด / App crash |
| Network Error | Backend ไม่ตอบ / DNS / SSL |

### Cloud Deployment Checklist:
- [ ] Port: ใช้ `$PORT` env variable ไม่ใช่ hardcode
- [ ] Health endpoint: `/api/health` ต้องตอบ 200
- [ ] Env vars: ตรวจสอบ `railway variables` / platform config
- [ ] Logs: ดู startup errors ก่อนแก้ code

### Debugging Flow:
```
Error → Verify basics (health, logs, config) → Diagnose → ONE change → Test → Repeat
       ↑                                                              |
       └──────────────────── ถ้า fail ────────────────────────────────┘
```

**Rule: ถ้าแก้ 2 ครั้งไม่สำเร็จ → STOP → Step back → วิเคราะห์ใหม่**

## Post-Implementation Review (REQUIRED)

```
Implement → Build → Code Review → UI Test (Playwright) → Commit
```

Before commit:
1. `/code-review` - security, error handling, validation
2. Playwright snapshot - verify UI works

## Git Commit Format
```
[type]: [description]
- What: [changes]
- Why: [reason]
Closes #[issue]
```
Types: `feat`, `fix`, `docs`, `refactor`, `test`, `chore`

## Project Stack
- **Backend**: Laravel 12 + Sanctum + PostgreSQL (Neon.tech)
- **Frontend**: React 19 + Vite + shadcn/ui + Tailwind v4
- **Deploy**: Railway (backend + frontend)
- **Real-time**: Laravel Reverb

## Production URLs
- Frontend: `https://frontend-production-9fe8.up.railway.app`
- Backend: `https://backend-production-b216.up.railway.app`

## Key Commands
```bash
# Backend
cd backend && composer install && php artisan serve

# Frontend
cd frontend && npm install && npm run dev

# Railway
railway logs                    # View logs
railway variables              # Check env vars
railway up --service backend   # Deploy
```

## Skills Reference
| Skill | When to Use |
|-------|-------------|
| `/feature-dev` | Start new feature |
| `/code-review` | Before commit |
| `/commit` | Create commit |
| `/e2e-test` | After feature complete |
| `ui-ux-pro-max` | UI design (auto) |

## User Preferences
- Scope: Tasks < 1 hour
- Approach: ทีละขั้น ไม่รีบ
- Communication: บอก strategy ก่อนลงมือ
- Feedback: ถ้า approach ผิด บอกเลย

## Lessons Learned
1. **Verify before fix** - Health check ก่อนแก้ไขเสมอ
2. **Infrastructure first** - Check Dockerfile/Procfile ก่อน app code
3. **One change at a time** - ไม่แก้หลายอย่างพร้อมกัน
4. **Explain strategy** - บอกว่าจะทำอะไรก่อนลงมือ
5. **Stop if stuck** - ถ้าแก้ 2 ครั้งไม่สำเร็จ ต้อง step back
