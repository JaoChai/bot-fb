# Claude Code Configuration

This directory contains Claude Code configuration files for the BotFacebook project.

## Directory Structure

```
.claude/
├── README.md                         # This file
├── COMMANDS.md                       # Backend/Frontend/Railway commands reference
├── UI-RULES.md                       # UI development rules and checklists
├── settings.local.json               # Permissions configuration
├── hookify.*.local.md                # Auto-trigger rules (Hookify)
├── mcp/                              # Model Context Protocol servers
│   ├── botfacebook-mcp-server/       # Main MCP server (diagnose, fix, bot_manage, etc.)
│   └── laravel-mcp-server/           # Laravel integration (routes, tinker, schema)
└── skills/                           # Custom skills (13 skills)
    ├── ui-ux-pro-max/                # Design intelligence
    ├── rag-evaluator/                # RAG pipeline evaluation
    ├── prompt-engineer/              # Prompt testing
    └── ...                           # And more
```

---

## Hookify Rules (Auto-Trigger)

| Rule | Trigger Keywords | Action |
|------|------------------|--------|
| `error-diagnose` | error, 500, ล่ม, พัง, crash | Use `diagnose` tool first |
| `deploy-check` | deploy, railway, production | Check `railway_status` first |
| `bot-manage` | bot, flow, บอท, KB | Use `bot_manage` tools |

Rules are active immediately - no restart needed.

---

## MCP Servers

### botfacebook-mcp-server (Main)
| Tool | Purpose |
|------|---------|
| `diagnose` | System health checks |
| `fix` | Apply fixes (cache, migrate, etc.) |
| `bot_manage` | Bot/Flow/KB/Conversation management |
| `evaluate` | Bot quality evaluation |
| `execute` | Railway, tests, tinker |

### laravel-mcp-server
| Tool | Purpose |
|------|---------|
| `inspect_database_schema` | Query database structure |
| `list_routes` | List Laravel routes |
| `run_tinker` | Execute PHP in Laravel context |

**Configuration**: See `/.mcp.json` at project root.

---

## Skills (13 Available)

| Skill | Purpose |
|-------|---------|
| `ui-ux-pro-max` | Design intelligence (styles, colors, typography) |
| `rag-evaluator` | RAG pipeline quality measurement |
| `prompt-engineer` | Prompt testing and optimization |
| `cost-monitor` | LLM cost tracking |
| `line-expert` | LINE integration debugging |
| `thai-nlp` | Thai language processing |
| `e2e-test` | End-to-end testing |
| `railway-deploy` | Railway deployment |
| `neon-database` | Neon PostgreSQL operations |
| `laravel-debugging` | Laravel error debugging |
| `facebook-bot-testing` | Facebook bot flow testing |
| `migration-validator` | Database migration safety |

---

## Related Documentation

- **CLAUDE.md** - Main project guidelines and mandatory rules
- **COMMANDS.md** - Quick command reference
- **UI-RULES.md** - UI development checklist
- **.mcp.json** - MCP server configuration
