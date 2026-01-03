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
