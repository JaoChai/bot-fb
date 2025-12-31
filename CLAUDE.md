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
- Frontend: `https://frontend-production-9fe8.up.railway.app`
- Backend: `https://backend-production-b216.up.railway.app`

## Gotchas
| Issue | Fix |
|-------|-----|
| `config('x','')` returns null | `config('x') ?? ''` |
| Echo auth stale | Dynamic authorizer |
| PDO boolean fails | Disable emulate_prepares |

## Custom Agents (Auto-Trigger)

| Agent | Keywords | หน้าที่ |
|-------|----------|--------|
| `system-health-agent` | error, 500, ล่ม | Debug + auto-fix |
| `bot-quality-agent` | ประเมิน, evaluate | Evaluation + improve |
| `kb-agent` | KB, embedding, RAG | Search optimization |
| `deploy-agent` | deploy, railway | Safe deployment |

> Agents auto-discover from `.claude/agents/` with hooks in `.claude/hooks/agent-*.md`

## MCP Tools

5 composite tools: `diagnose`, `fix`, `bot_manage`, `evaluate`, `execute`

> Auto-triggered via hooks. See `.claude/hooks/mcp-auto-*.md`

## See Also
- [COMMANDS.md](.claude/COMMANDS.md)
- [UI-RULES.md](.claude/UI-RULES.md)
