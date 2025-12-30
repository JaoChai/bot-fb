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

### ⛔ STOP! ก่อนแก้ error ต้องทำสิ่งนี้ก่อน:
```
1. หา ACTUAL ERROR MESSAGE ก่อน (ไม่ใช่แค่เห็น 500/502)
   - ถ้า logs timeout → สร้าง debug endpoint
   - ถ้าเห็น HTML error → หา exception message จริง

2. เข้าใจว่า error เกิดที่ไหน:
   - DI/Service instantiation? → ทดสอบ new Service()
   - Controller logic? → ดู stack trace
   - View/Response? → ดู response format

3. ทดสอบสมมติฐานก่อนแก้ (ไม่ใช่เดาแล้วแก้)
```

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

## UI Development Rules (MANDATORY)

เมื่อทำงานเกี่ยวกับ UI/UX ต้องใช้ทั้งสองระบบร่วมกัน:

### 1. ui-ux-pro-max (Design Intelligence)
```bash
# ค้นหา design context ก่อน implement
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<keyword>" --domain style
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<keyword>" --domain typography
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<keyword>" --domain color
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<keyword>" --domain ux
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<keyword>" --stack react
```

### 2. shadcn/ui (Components)
```bash
# เพิ่ม component ใหม่
cd frontend && npx shadcn add [component-name]

# Components ที่มี: Button, Card, Dialog, Sheet, Input, Select,
# Tabs, Dropdown, Avatar, Badge, ScrollArea, Tooltip, etc.
```

### UI Workflow
```
User Request → Search ui-ux-pro-max → Get Design Decisions →
Use shadcn/ui Components → Apply Tailwind Styles →
Check Pre-delivery Checklist → Implement
```

### Pre-delivery Checklist
- [ ] No emoji icons (ใช้ Lucide icons)
- [ ] cursor-pointer บน clickable elements
- [ ] Dark/Light mode contrast ถูกต้อง
- [ ] Responsive: 320px, 768px, 1024px, 1440px
- [ ] Hover states ไม่ทำให้ layout shift

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
6. **Get ACTUAL error first** - เห็น 500 ไม่พอ ต้องหา exception message จริง ก่อนวิเคราะห์
7. **Laravel config() gotcha** - `config('key', '')` คืน null ไม่ใช่ '' → ใช้ `config('key') ?? ''` แทน
