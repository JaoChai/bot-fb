# BotFacebook

## Stack
- Laravel 12 + React 19 + PostgreSQL (Neon)
- Railway (deploy) + Reverb (WebSocket)

## URLs
| Service | URL |
|---------|-----|
| Frontend | https://www.botjao.com |
| Backend | https://api.botjao.com |

## Gotchas
| Problem | Fix |
|---------|-----|
| `config('x','')` returns null | Use `config('x') ?? ''` |
| API response wrapped in `{data:X}` | Access `response.data` |
| Railway serve.json fails | Use Express server |

## Code Change Rules (Minimal Change Principle)

เมื่อแก้ไข bug หรือ update feature:

| Rule | Description |
|------|-------------|
| แก้เฉพาะจุด | ห้ามแก้ไขโค้ดที่ไม่เกี่ยวกับปัญหาโดยตรง |
| ห้าม refactor | ถ้าเจอโค้ดที่อยากปรับ ให้แยกเป็น task ใหม่ |
| ห้ามเพิ่ม feature | focus เฉพาะการแก้ปัญหาที่ได้รับมอบหมาย |
| ตรวจ git diff | ก่อน commit ต้องมีเฉพาะไฟล์ที่เกี่ยวข้อง |

### Before Commit Checklist
- [ ] ไฟล์ที่แก้ไขเกี่ยวข้องกับ task โดยตรงทั้งหมด?
- [ ] ไม่มีการ refactor/cleanup ที่ไม่เกี่ยวข้อง?
- [ ] ไม่มีการเพิ่ม feature ใหม่ที่ไม่ได้ขอ?

## When Debugging
- Search memory first for similar bugs
- Use MCP `diagnose` tool for system health check

## Required Skills
| Task | Skill | When |
|------|-------|------|
| UI/UX Design | `ui-ux-pro-max` | ทุกครั้งที่ปรับ design, สร้าง component, แก้ไข styling, layout |
| WebSocket/Realtime | `websocket-debugger` | debug broadcast, Echo, Reverb, race condition, realtime issues |
| React Query | `react-query-expert` | debug mutation, cache, refetch, UI ไม่ update, stale data |
| Railway Deploy | `railway-deployer` | deploy issues, production debugging, env vars, logs |
| LINE Integration | `line-expert` | Flex Message, webhook, LINE API errors |
| Thai Search | `thai-nlp` | semantic search ภาษาไทย, threshold, tokenization |

## Agent + Skill + MCP Sets

Hook `smart-agent-selector.sh` แนะนำ SET ที่เหมาะสมทุก prompt

| Set | Agent | Skills | MCP |
|-----|-------|--------|-----|
| `frontend-dev` | frontend-developer | ui-ux-pro-max, react-query-expert | Context7, Chrome |
| `backend-dev` | backend-developer | - | Context7, Neon |
| `database` | db-manager | migration-validator | Neon |
| `rag-debug` | rag-debugger | rag-evaluator, thai-nlp | Neon, Memory |
| `webhook-debug` | webhook-tracer | line-expert, websocket-debugger | Neon, Railway |
| `performance` | performance-analyzer | react-query-expert | Neon, Chrome |
| `deployment` | - | railway-deployer | Railway |

ดู `.claude/sets.json` สำหรับ config ทั้งหมด (15 sets)
ดู `.claude/agents/[name].md` สำหรับ methodology

## Feature Development (Speckit Workflow)

เมื่อ user ขอให้พัฒนา feature ใหม่ **ให้ใช้ speckit workflow อัตโนมัติ**

### เมื่อไหร่ต้องใช้ Speckit
- User ขอ "เพิ่ม feature...", "สร้าง...", "implement...", "พัฒนา..."
- งานที่ต้องสร้าง/แก้ไขหลายไฟล์
- งานที่ใช้เวลามากกว่า 30 นาที

### Workflow อัตโนมัติ
```
1. /speckit.specify "[feature description]"
   → สร้าง branch + spec.md

2. /speckit.clarify (ถ้า requirements ไม่ชัด)
   → ถามคำถาม clarify

3. /speckit.plan
   → สร้าง technical plan

4. /speckit.tasks
   → สร้าง task breakdown

5. /speckit.implement
   → ลงมือทำตาม tasks
```

### ไม่ต้องใช้ Speckit เมื่อ
- Bug fix เล็กๆ (ใช้ skills แทน)
- แก้ไข config/env
- งานที่แก้ไฟล์เดียว
- User บอกชัดเจนว่าไม่ต้องการ speckit

## Git Flow

**ทุกครั้งที่พัฒนา feature/fix ต้องทำตาม flow นี้**

### Branch Strategy
```
main (production)
  └── feature/xxx   ← feature ใหม่
  └── fix/xxx       ← bug fix
  └── chore/xxx     ← config, docs, cleanup
```

### Workflow
1. **ก่อนเริ่มงาน** → `git checkout -b feature/ชื่อ-feature` หรือ `fix/ชื่อ-bug`
2. **ระหว่างทำ** → commit เป็นชุดเล็กๆ ด้วย conventional commits
3. **เสร็จงาน** → ใช้ `/commit-push-pr` สร้าง PR

### Conventional Commits
| Prefix | ใช้เมื่อ |
|--------|---------|
| `feat:` | เพิ่ม feature ใหม่ |
| `fix:` | แก้ bug |
| `chore:` | งาน maintenance, dependencies |
| `refactor:` | ปรับโครงสร้าง code |
| `docs:` | แก้ documentation |
| `style:` | formatting, UI styling |
| `test:` | เพิ่ม/แก้ tests |

### Integration กับ Speckit
- `/speckit.specify` สร้าง branch อัตโนมัติ
- งานเล็กที่ไม่ใช้ speckit → **ต้องสร้าง branch manual ก่อนเริ่มงาน**
