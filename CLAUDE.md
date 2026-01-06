# CLAUDE.md - BotFacebook

## Stack
- Laravel 12 + React 19 + PostgreSQL (Neon)
- Railway (deploy) + Reverb (WebSocket)

## URLs
| Service | URL |
|---------|-----|
| Frontend | https://www.botjao.com |
| Backend | https://api.botjao.com |

---

## Auto Debug Workflow

เมื่อเจอ bug/error → ทำตาม flow นี้ AUTO:

### Flow (MUST FOLLOW IN ORDER)
```
1. Discovery Agent → ค้น memory ก่อน
2. Diagnosis Agent → วิเคราะห์ logs/code
3. Auto-Approve → ถ้า confidence >= 80%
4. Process Agent → fix, commit, verify
```

### Agent Descriptions (for auto-delegation)
| Agent | When to Use | Tools |
|-------|-------------|-------|
| discovery | USE FIRST - search memory for similar bugs | mem-search, Read, Grep |
| diagnosis | AFTER discovery - analyze logs/code | diagnose, Read, Grep, Bash |
| process | AFTER diagnosis + confidence >= 80% | Edit, Write, Bash, gh |

### Auto-Approve Rules
| Confidence | Action |
|------------|--------|
| >= 80% | AUTO PROCEED to Process |
| 60-79% | Ask user for confirmation |
| < 60% | Ask user for more info |

---

## Memory Tags

```
[GOTCHA][PROJECT:BotFacebook]   # กับดัก - ค้นหาก่อน debug!
[PATTERN][PROJECT:BotFacebook]  # รูปแบบที่ดี
[RULE:*][PROJECT:BotFacebook]   # กฎที่ต้องทำตาม
```

## Context Management

### Thresholds (Auto Warning)
| Level | % | Action |
|-------|---|--------|
| Normal | 0-50% | No warning |
| Tip | 50-70% | Show tip |
| Warning | 70-90% | Show warning box |
| Critical | 90%+ | Recommend new session |

### Rules (MUST FOLLOW)
| Rule | Description |
|------|-------------|
| Parallel Calls | Tool calls ที่ไม่ depend กัน → เรียกพร้อมกัน |
| Agent Delegation | งานหนัก (explore, search) → delegate ให้ agent |
| Memory First | ก่อน Read file → search memory ก่อน |
| Concise Output | เมื่อ > 70% → keep outputs สั้น |

---

## Known Gotchas

| Problem | Fix |
|---------|-----|
| `config('x','')` null | `config('x') ?? ''` |
| API wrapped `{data:X}` | `response.data` |
| serve.json fail Railway | Express server |
