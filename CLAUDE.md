# CLAUDE.md - BotFacebook

## STOP! อ่านก่อนทำอะไร

```
┌─────────────────────────────────────────────────────────────┐
│  🚨 กฎเหล็ก: ดูจริง ก่อนแก้                                  │
├─────────────────────────────────────────────────────────────┤
│  ❌ ห้าม: "น่าจะเป็น..." → ลุยแก้                            │
│  ❌ ห้าม: เดาว่า API return อะไร                             │
│  ❌ ห้าม: แก้ 2 รอบไม่หาย แล้วลุยต่อ                         │
│                                                             │
│  ✅ ต้อง: เปิดไฟล์ดูจริง → แล้วค่อยแก้                       │
│  ✅ ต้อง: 2 รอบไม่หาย → หยุด ถาม user                        │
└─────────────────────────────────────────────────────────────┘
```

## Decision Tree - ทำตามนี้เลย

```
User บอกปัญหา
    │
    ├─ "API/Toggle/Data ไม่ทำงาน"
    │   └─ ไป → [Checklist A: API Issues]
    │
    ├─ "Deploy แล้วไม่เห็น/Cache"
    │   └─ ไป → [Checklist B: Deploy Issues]
    │
    ├─ "Error/พัง/500"
    │   └─ ไป → [Checklist C: Debug Issues]
    │
    └─ "เพิ่ม Feature"
        └─ ไป → [Checklist D: New Feature]
```

---

## Checklist A: API Issues

```
□ 1. มี GitHub Issue เกี่ยวกับเรื่องนี้ไหม?
     gh issue list --search "keyword"
     → ถ้ามี อ่านก่อน อาจมีคำตอบแล้ว

□ 2. Backend return อะไร?
     → เปิด Controller ดู return statement จริง
     → จด: return { ??? }

□ 3. Frontend expect อะไร?
     → เปิด hook/component ดู type จริง
     → จด: expect type { ??? }

□ 4. ตรงกันไหม?
     → ถ้าไม่ตรง แก้ให้ตรง → จบ
     → ถ้าตรง ปัญหาอยู่ที่อื่น → ถาม user
```

## Checklist B: Deploy Issues

```
□ 1. Production serve อะไรอยู่?
     curl -I https://www.botjao.com/
     → จด headers ที่เห็น

□ 2. Headers ถูกต้องไหม?
     → index.html ต้อง no-cache
     → assets/*.js ต้อง immutable

□ 3. ถ้าไม่ถูก → แก้ server config
□ 4. Test local ก่อน deploy
□ 5. Deploy แล้ว curl -I อีกครั้ง verify
```

## Checklist C: Debug Issues

```
□ 1. ดู actual error ก่อน
     → diagnose logs / railway_logs
     → จด error message จริง

□ 2. Search memory
     → mem-search "error message"
     → เคยแก้ไหม? แก้ยังไง?

□ 3. ถ้าแก้ 2 รอบไม่หาย → หยุด ถาม user
```

## Checklist D: New Feature

```
□ 1. เข้า Plan Mode ก่อน
□ 2. วางแผน → ให้ user approve
□ 3. Implement ตาม plan
□ 4. npm run build ก่อน commit
```

---

## Quick Reference

| Command | ใช้เมื่อ |
|---------|---------|
| `ccc` | context+compact |
| `nnn` | plan |
| `gogogo` | execute |
| `gh issue list` | หา issue ที่เกี่ยวข้อง |
| `curl -I URL` | ดู headers จริง |

## Stack
Laravel 12 + React 19 + PostgreSQL (Neon) + Railway + Reverb

## URLs
- Frontend: `https://www.botjao.com`
- Backend: `https://api.botjao.com`

## MCP Tools
| Tool | Purpose |
|------|---------|
| `diagnose` | Health check (all, backend, logs, railway) |
| `fix` | Apply fixes (clear_cache, optimize, migrate) |
| `bot_manage` | Bot/Flow/KB operations |
| `execute` | railway_status, railway_logs, tinker |

## Gotchas (ปัญหาที่เจอบ่อย)
| Issue | Fix |
|-------|-----|
| `config('x','')` returns null | `config('x') ?? ''` |
| API return wrapped `{data: X}` | ต้อง `response.data` |
| serve.json ไม่ work บน Railway | ใช้ Express server แทน |

---

*อัพเดท: 3 ม.ค. 2026 - Refactor ให้ฉลาดขึ้น*
