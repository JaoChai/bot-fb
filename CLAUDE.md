# CLAUDE.md - Autonomous Mode

## Core Rules

| # | Rule | Action |
|---|------|--------|
| 1 | ห้ามเดา | ดูจริงก่อนแก้ (เปิดไฟล์/curl/logs) |
| 2 | ห้ามแก้มัว | 2 รอบไม่หาย → หยุด ถาม User |
| 3 | ใช้ Memory | `mem-search` ก่อนทำทุกครั้ง |
| 4 | เรียนรู้ | ผิด → บันทึก LESSONS.md ทันที |
| 5 | **ห้ามถามเรื่อง learning** | บันทึกเอง commit เอง ไม่ต้องขออนุญาต |

## Self-Learning Protocol (ห้ามถาม ทำเลย)

```
เมื่อเกิดเหตุการณ์ต่อไปนี้:
├── ทำผิด/แก้ไม่ได้        → บันทึก LESSONS.md ทันที
├── เรียนรู้วิธีใหม่        → บันทึก LESSONS.md ทันที
├── เจอ pattern ที่ดี      → บันทึก LESSONS.md ทันที
└── ถูก user ท้าทาย/แก้ไข  → บันทึก LESSONS.md ทันที

❌ ห้าม: "ต้องการให้ผมเพิ่มไหมครับ?"
❌ ห้าม: "ควรบันทึกเรื่องนี้ไหม?"
✅ ต้อง: Edit → Commit → Push → แจ้ง user ว่าทำแล้ว
```

## Work Loop

```
Feature: RESEARCH → SPEC → PLAN → TASKS → EXECUTE → VERIFY → DEPLOY → LEARN
Bug:     MEMORY → VERIFY → ISSUE → RECHECK → FIX → VERIFY → LEARN
```

## Feature Development Workflow (Full Auto)

```
User ขอ Feature ใหม่
     │
     ▼
┌─────────────────────────────────────┐
│ 1. RESEARCH (ใช้ Memory เต็มที่)      │
│    → search(type=feature) หา feature คล้ายๆ │
│    → search(type=decision) หา decisions     │
│    → timeline(anchor=ID) ดู context         │
│    → get_observations(ids) ดึงรายละเอียด    │
│    → Explore codebase หา pattern            │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 2. SPEC → /speckit.specify          │
│    → Output: specs/xxx/spec.md      │
│    → Feature ทำอะไร?                │
│    → User story, Scope              │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 3. PLAN → /speckit.plan             │
│    → Output: specs/xxx/plan.md      │
│    → Backend: API/DB                │
│    → Frontend: Component/hooks      │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 4. TASKS → /speckit.tasks           │
│    → Output: specs/xxx/tasks.md     │
│    → แบ่ง task ย่อย + dependency    │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 5. EXECUTE → /speckit.implement     │
│    → ทำตาม tasks.md ทีละข้อ         │
│    → Commit ทุก task ที่เสร็จ        │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 6. VERIFY                           │
│    → npm run build ผ่าน?            │
│    → ทดสอบ feature ทำงาน?           │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 7. DEPLOY + LEARN                   │
│    → git push (auto deploy)         │
│    → บันทึก LESSONS.md ถ้าเรียนรู้    │
└─────────────────────────────────────┘
```

### Feature Checklist (ก่อนเริ่ม Execute)

```
□ spec.md ชัดเจน? (รู้ว่าทำอะไร)
□ plan.md สมเหตุสมผล? (รู้ว่าทำยังไง)
□ tasks.md แตกย่อยแล้ว?
□ มี pattern ใน codebase ให้ดูไหม?
```

## Bug Fixing Workflow (Full Auto)

```
User แจ้ง Bug
     │
     ▼
┌─────────────────────────────────────┐
│ 1. MEMORY (ใช้ Memory เต็มที่)        │
│    → search(type=bugfix) หา bug คล้ายๆ      │
│    → search(type=decision) หา decisions     │
│    → timeline(anchor=ID) ดู context         │
│    → get_observations(ids) ดึงรายละเอียดวิธีแก้│
│    ⚠️ Memory = HINT ต้อง verify กับ realtime │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 2. Verify กับ Realtime Data         │
│    ⚠️ Memory อาจ outdated!          │
│    ✅ ดู logs จริง (diagnose/railway)│
│    ✅ อ่าน code ปัจจุบัน             │
│    ✅ เช็คว่าไฟล์/บรรทัดยังตรงไหม    │
│    ✅ "หน้า A work หน้า B ไม่"       │
│      → เปรียบเทียบ 2 ไฟล์ทันที       │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 3. สร้าง GitHub Issue               │
│    - Title: Bug: [ปัญหา]            │
│    - Root cause (จาก realtime data) │
│    - Plan แก้ไข                     │
│    - Confidence %                   │
│    (สร้างเพื่อ track ไม่ต้องรอ approve)│
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 4. RECHECK ตัวเอง                   │
│    □ Root cause ถูกจริงไหม?         │
│    □ Plan สมเหตุสมผลไหม?            │
│    □ ไม่มั่วใช่ไหม?                  │
│    □ Confidence >= 80%?             │
│    ถ้าไม่มั่นใจ → กลับข้อ 2          │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 5. แก้ไขทันที → Deploy              │
│    (ไม่ต้องรอ approve)              │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 6. Verify (Deploy ≠ Done)           │
│    ✅ work → Close Issue + LESSONS  │
│    ❌ ไม่ work → Update Issue กลับข้อ 2│
│    ❌ 2 รอบไม่หาย → หยุด ถาม User    │
└─────────────────────────────────────┘
```

### claude-mem Usage Guide

**Search Workflow:**
```
1. search(query, type, obs_type) → ได้ IDs
2. timeline(anchor=ID) → ดู context รอบๆ
3. get_observations(ids) → ดึงรายละเอียด
```

**obs_type ที่ใช้ได้:**
| Type | ใช้หา |
|------|-------|
| `bugfix` | Bug ที่เคยแก้ |
| `feature` | Feature ที่เคยทำ |
| `decision` | การตัดสินใจ architectural |
| `discovery` | สิ่งที่ค้นพบ/เรียนรู้ |
| `change` | การเปลี่ยนแปลง code |

**ตัวอย่าง:**
```
# หา bug คล้ายๆ
search(query="toggle", obs_type="bugfix", project="BotFacebook")

# หา decisions เกี่ยวกับ React Query
search(query="React Query", obs_type="decision", project="BotFacebook")

# ดู context รอบ observation #14820
timeline(anchor=14820, depth_before=3, depth_after=3, project="BotFacebook")
```

### Data Reliability

| แหล่ง | Realtime | ใช้เป็น |
|-------|----------|--------|
| `diagnose logs` | ✅ | **Source of Truth** |
| `railway_logs` | ✅ | **Source of Truth** |
| Read files | ✅ | **Source of Truth** |
| Grep | ✅ | **Source of Truth** |
| `mem-search` | ❌ | **Hint เท่านั้น** ต้อง verify |
| LESSONS.md | ⚠️ | Pattern ทั่วไป OK, specific อาจ outdated |

### Anti-Overconfidence Protocol (บังคับ)

**ก่อนบอกว่า "มั่นใจ X%":**
```
□ มีหลักฐานจาก realtime data? (logs/code จริง)
□ Reproduce bug ได้ไหม? (เห็นปัญหาเกิดขึ้นจริง)
□ ถ้าแก้แล้วผิด จะรู้ได้ยังไง?
□ มี alternative explanation ไหม?
```

**Confidence Level ที่ถูกต้อง:**
| Level | Criteria |
|-------|----------|
| 90%+ | เห็น root cause ใน logs + มี code ที่ work เปรียบเทียบ |
| 80% | เห็น root cause ใน logs หรือ code |
| 70% | มี hypothesis ที่สมเหตุสมผล แต่ยังไม่ confirm |
| <70% | **ต้องหาข้อมูลเพิ่ม ห้ามแก้** |

### Verification Methods

| ประเภท Fix | วิธี Verify |
|------------|------------|
| UI ไม่อัพเดท | User ลองกดดู / Playwright test |
| API Error | `curl` endpoint / ดู logs |
| Data ไม่ถูก | Query database ดู |
| Deploy ไม่ขึ้น | `railway_logs` / `curl -I` |

## ไม่มั่นใจ? ใช้แหล่งอ้างอิงเหล่านี้

| แหล่ง | Tool/Command |
|-------|--------------|
| Memory (เคยแก้ไหม?) | `mem-search "keyword"` |
| LESSONS.md | Read file |
| Code ที่ work | เปรียบเทียบ 2 ไฟล์ |
| Logs จริง | `diagnose logs` / `railway_logs` |
| Codebase patterns | `Grep` หา pattern |
| Library docs | `Context7` query-docs |

## Safety Stops (ต้องถาม User)

Destructive | Cost | Security | Ambiguous | Failed 2x

## Tool Selection Guide

### เมื่อไหร่ใช้อะไร

| Situation | Tool |
|-----------|------|
| **System health/logs** | `diagnose` (MCP) |
| **Clear cache/migrate** | `fix` (MCP) |
| **Bot/Flow/KB ops** | `bot_manage` (MCP) |
| **Railway deploy/logs** | `execute` (MCP) |
| **Database query** | `mcp__neon__run_sql` |
| **Error tracking** | `mcp__sentry__*` |
| **UI testing** | `mcp__playwright__*` |
| **ค้นหา codebase** | Task → `Explore` agent |
| **วางแผน implementation** | Task → `Plan` agent |
| **Review code** | Task → `code-reviewer` agent |
| **Debug Laravel 500** | Skill → `laravel-debugging` |
| **E2E testing** | Skill → `e2e-test` |
| **Design UI** | Skill → `ui-ux-pro-max` |
| **Debug LINE** | Skill → `line-expert` |
| **Check migration safety** | Skill → `migration-validator` |
| **Feature spec** | `/speckit.specify` |
| **Technical plan** | `/speckit.plan` |
| **Task breakdown** | `/speckit.tasks` |
| **Execute plan** | `/speckit.implement` |

## Realtime Search Protocol (เรื่องใหม่)

```
เมื่อเจอ Topic ใหม่ที่ไม่รู้จัก:
     │
     ▼
┌─────────────────────────────────────┐
│ 1. CHECK: เรื่องใหม่จริงไหม?         │
│    → mem-search(topic) → เคยทำ?     │
│    → memory มี? → ใช้ memory ก่อน    │
│    → memory ไม่มี/outdated? → ข้อ 2 │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 2. SEARCH: ค้นหา Token-Efficient    │
│    Priority Order:                  │
│    ① Context7 (library docs)        │
│      → resolve-library-id ก่อน      │
│      → query-docs เฉพาะที่ต้องการ    │
│    ② WebSearch (best practices)     │
│      → ใส่ปี 2025-2026 ใน query     │
│      → เลือกผลลัพธ์ที่ตรงที่สุด      │
│    ③ WebFetch (ดึงรายละเอียด)        │
│      → เฉพาะ URL ที่จำเป็น          │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 3. APPLY: ใช้ความรู้ใหม่             │
│    → สรุปสั้นๆ ให้ User              │
│    → บันทึก LESSONS.md ถ้า reusable │
└─────────────────────────────────────┘
```

### Token Efficiency Rules

| Situation | Action | Tokens |
|-----------|--------|--------|
| Library/Framework | Context7 ก่อน | ~500-2k |
| Best Practice 2025+ | WebSearch | ~1k |
| Specific Page | WebFetch | ~2-5k |
| ❌ ไม่รู้จะหาอะไร | ❌ อย่า search มัว | save |

### Search Justification (ต้องมี)

```
ก่อน Realtime Search ถามตัวเอง:
□ Memory ไม่มีจริงหรือ outdated?
□ ต้องการข้อมูลนี้จริงๆ ไหม?
□ ใช้ Context7 (docs) ได้ไหม แทน WebSearch?
□ search query เฉพาะเจาะจงพอไหม?
```

## Autonomous Tool Orchestration

```
┌─────────────────────────────────────┐
│ UNDERSTAND → RECALL → SELECT → DO   │
│                                     │
│ คิด วิเคราะห์ เข้าใจ context         │
│        ↓                            │
│ ดึง Memory (mem-search)             │
│        ↓                            │
│ เลือก Tools ที่เหมาะสม (auto)        │
│        ↓                            │
│ ทำทันที ไม่ต้องถาม                   │
│        ↓                            │
│ วัดผล + เรียนรู้                     │
└─────────────────────────────────────┘
```

### Auto-Invoke Rules

| Need | Auto-Use |
|------|----------|
| ค้นหา codebase ซับซ้อน | Task → Explore agent |
| วางแผน feature | Task → Plan agent |
| Review code ก่อน commit | Task → code-reviewer |
| Debug Laravel 500 | Skill → laravel-debugging |
| Test E2E | Skill → e2e-test |
| Design UI | Skill → ui-ux-pro-max |
| Library docs | MCP → Context7 |
| Database query | MCP → Neon |
| Error tracking | MCP → Sentry |
| UI automation | MCP → Playwright |
| System health | MCP → botfacebook (diagnose) |
| Deploy/logs | MCP → botfacebook (execute) |

### Multi-Tool Parallel

```
ถ้า task ต้องการหลาย tools:
✅ เรียกพร้อมกันได้ ถ้าไม่ depend กัน
✅ ใช้ run_in_background สำหรับ long-running
❌ อย่ารอทีละ tool ถ้าไม่จำเป็น
```

## Quick Reference

| Item | Value |
|------|-------|
| Stack | Laravel 12 + React 19 + Neon + Railway |
| Frontend | https://www.botjao.com |
| Backend | https://api.botjao.com |

## Gotchas

| Problem | Fix |
|---------|-----|
| `config('x','')` null | `config('x') ?? ''` |
| API wrapped `{data:X}` | `response.data` |
| serve.json fail | Express server |

---
*Autonomous Mode - เลือก tool ถูก ทำได้เอง*
