# CLAUDE.md - BotFacebook

## Quick Codes
`ccc` context+compact | `nnn` plan | `gogogo` execute | `lll` status | `rrr` retrospective

## Critical Rules
- **NEVER** force flags, merge without approval, `rm -rf`
- **NEVER** guess root cause - get ACTUAL error first
- **STOP** after 2 failed fix attempts
- **ALWAYS** `npm run build` before commit

## Stack
Laravel 12 + React 19 + PostgreSQL (Neon) + Railway + Reverb

## URLs
- Frontend: `https://www.botjao.com`
- Backend: `https://api.botjao.com`

## Gotchas
| Issue | Fix |
|-------|-----|
| `config('x','')` returns null | `config('x') ?? ''` |
| Echo auth stale | Dynamic authorizer |
| PDO boolean fails | Disable emulate_prepares |

## MCP Tools
| Tool | Purpose | Actions |
|------|---------|---------|
| `diagnose` | Health check | all, backend, database, logs, railway |
| `fix` | Apply fixes | clear_cache, optimize, migrate |
| `bot_manage` | Bot/Flow/KB | list_bots, test_bot, search_kb |
| `evaluate` | Bot eval | create, progress, report |
| `execute` | Operations | railway_status, railway_logs, tinker |

## See Also
- [COMMANDS.md](.claude/COMMANDS.md) | [UI-RULES.md](.claude/UI-RULES.md)
