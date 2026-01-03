# CLAUDE.md - BotFacebook

## Bootstrap

```
Session Start:
1. rules-manager LOAD → โหลด rules จาก mem
2. Apply rules ตลอด session
3. Learn + tag เมื่อเจอสิ่งใหม่

Rules อยู่ใน mem (ไม่ได้อยู่ในไฟล์นี้)
→ mem-search "[RULE:*]" เพื่อดู rules ทั้งหมด
```

## Stack
- Laravel 12 + React 19 + PostgreSQL (Neon)
- Railway (deploy) + Reverb (WebSocket)

## URLs
| Service | URL |
|---------|-----|
| Frontend | https://www.botjao.com |
| Backend | https://api.botjao.com |

## Tagging Convention

```
Learning Tags:
[PATTERN][SCOPE]      รูปแบบที่ดี
[MISTAKE][SCOPE]      ข้อผิดพลาด
[GOTCHA][SCOPE]       กับดัก
[PROCESS][SCOPE]      วิธีทำงาน
[ARCHITECTURE][SCOPE] การออกแบบ

Rule Tags:
[RULE:BUDGET][SCOPE]       Token budget
[RULE:AGENT][SCOPE]        Agent selection
[RULE:WORKFLOW][SCOPE]     Workflow patterns
[RULE:OPTIMIZATION][SCOPE] Optimization

Scopes:
[UNIVERSAL]           ทุก project
[TECHNOLOGY:xxx]      เฉพาะ tech
[PROJECT:BotFacebook] เฉพาะ project นี้
```

## Quick Search

```bash
# หา rules ทั้งหมด
mem-search "[RULE:"

# หา patterns
mem-search "[PATTERN]"

# หา gotchas
mem-search "[GOTCHA]"

# หา Laravel specific
mem-search "[TECHNOLOGY:Laravel]"
```

## Gotchas (Quick Ref)

| Problem | Fix |
|---------|-----|
| `config('x','')` null | `config('x') ?? ''` |
| API wrapped `{data:X}` | `response.data` |
| serve.json fail Railway | Express server |

---
*Rules managed by rules-manager agent in mem*
