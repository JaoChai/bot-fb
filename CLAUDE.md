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
RECEIVE → RESEARCH → SPEC → PLAN → TASKS → ISSUE → EXECUTE → VERIFY → COMMIT → LEARN
```

## Feature Development Workflow (Full Auto)

```
User ขอ Feature ใหม่
     │
     ▼
┌─────────────────────────────────────┐
│ 1. RESEARCH                         │
│    → mem-search "feature keyword"   │
│    → Explore codebase หา pattern    │
│    → ดูว่ามี code คล้ายๆ ไหม         │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 2. SPEC (ระบุให้ชัด)                 │
│    → Feature ทำอะไร?                │
│    → User story คืออะไร?            │
│    → Scope อะไรบ้าง? อะไรไม่ทำ?      │
│    → Tool: /speckit.specify         │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 3. PLAN (วางแผนเทคนิค)               │
│    → Backend: API/DB อะไรบ้าง?      │
│    → Frontend: Component ไหน?       │
│    → ไฟล์ที่ต้องแก้/สร้าง            │
│    → Tool: /speckit.plan            │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 4. TASKS (แตก task ย่อย)             │
│    → แบ่งเป็น task เล็กๆ             │
│    → เรียงลำดับ dependency          │
│    → Tool: /speckit.tasks           │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 5. GitHub Issue                     │
│    → Title: Feature: [ชื่อ feature]  │
│    → Body: Spec + Plan + Tasks      │
│    → (สร้างเพื่อ track)              │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 6. EXECUTE (ทำทีละ task)             │
│    → ทำตาม task list                │
│    → Commit ทุก task ที่เสร็จ        │
│    → Tool: /speckit.implement       │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 7. VERIFY                           │
│    → npm run build ผ่าน?            │
│    → ทดสอบ feature ทำงาน?           │
│    → E2E test (ถ้ามี)               │
└─────────────────────────────────────┘
     │
     ▼
┌─────────────────────────────────────┐
│ 8. DEPLOY + LEARN                   │
│    → Deploy to production           │
│    → Close Issue                    │
│    → บันทึก LESSONS.md ถ้าเรียนรู้อะไร│
└─────────────────────────────────────┘
```

### Feature Checklist (ก่อนเริ่ม Execute)

```
□ Spec ชัดเจน? (รู้ว่าทำอะไร)
□ Plan สมเหตุสมผล? (รู้ว่าทำยังไง)
□ Tasks แตกย่อยแล้ว?
□ มี pattern ใน codebase ให้ดูไหม?
□ GitHub Issue สร้างแล้ว?
```

## Bug Fixing Workflow (Full Auto)

```
User แจ้ง Bug
     │
     ▼
┌─────────────────────────────────────┐
│ 1. เช็ค Memory ก่อน                  │
│    mem-search "keyword"             │
│    → ได้ HINT (ไม่ใช่คำตอบสุดท้าย)   │
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
