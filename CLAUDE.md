# CLAUDE.md - BotFacebook

## Quick Codes
`ccc` context+compact | `nnn` plan | `gogogo` execute | `lll` status | `rrr` retrospective

## Critical Rules
- **NEVER** force flags, merge without approval, `rm -rf`
- **NEVER** guess root cause - get ACTUAL error first
- **STOP** after 2 failed fix attempts - step back
- **ALWAYS** run `npm run build` before commit
- **ALWAYS** explain hypothesis before fixing

## Debug Protocol
```
Error → Get ACTUAL message → Hypothesis → User OK → ONE fix → Test
        ↑                                                    |
        └─────────── If fail 2x: STOP, step back ───────────┘
```

| Error | Action |
|-------|--------|
| 500 | Create debug endpoint / Laravel logs |
| 502 | Health check + Railway logs |
| 403/401 | Check token state + headers |
| CORS | Backend might be down, not CORS |

## Pre-Commit
```bash
cd frontend && npm run build  # MUST pass
```

## Stack
Laravel 12 + React 19 + PostgreSQL (Neon) + Railway + Reverb

## URLs
- Frontend: `https://frontend-production-9fe8.up.railway.app`
- Backend: `https://backend-production-b216.up.railway.app`

## Gotchas
| Issue | Fix |
|-------|-----|
| Laravel `config('x','')` returns null | Use `config('x') ?? ''` |
| Echo static auth stale after refresh | Use dynamic authorizer |
| PDO boolean fails on PostgreSQL | Disable emulate_prepares |

## Skills
`/feature-dev` `/code-review` `/commit` `/e2e-test` `ui-ux-pro-max`

## See Also
- [COMMANDS.md](.claude/COMMANDS.md) - Full command reference
- [UI-RULES.md](.claude/UI-RULES.md) - UI development guidelines
