# Claude Code Configuration

This directory contains Claude Code configuration files for the BotFacebook project.

## Directory Structure

```
.claude/
├── README.md                              # This file
├── hooks/                                 # Event-driven automation
│   ├── post-code-review-suggest.md       # Auto-suggest code review after writes
│   ├── post-commit-suggest.md             # Auto-suggest commit after changes
│   ├── post-frontend-design-note.md       # Notify about frontend-design activation
│   └── post-ui-ux-auto-activate.md        # Auto-activate ui-ux-pro-max skill
├── mcp/                                   # Model Context Protocol servers
│   └── laravel-mcp-server/               # Custom Laravel MCP server
│       ├── src/index.ts                  # Server source code
│       ├── dist/index.js                 # Built server
│       ├── package.json                  # Dependencies
│       └── README.md                     # Server documentation
└── skills/                               # Custom skills
    └── ui-ux-pro-max/                    # Design intelligence skill
        ├── SKILL.md                      # Skill definition
        ├── data/                         # Design databases (CSV)
        └── scripts/                      # Search scripts (Python)
```

---

## Hooks Configured (4 Hooks)

### 1. Post-Write Code Review Suggestion
**File**: `hooks/post-code-review-suggest.md`

**Behavior**: After you write or edit backend/frontend code, the system suggests running `/code-review`.

**Triggers**:
- Writing/editing `.php` files in `backend/app/`
- Writing/editing `.tsx`, `.ts`, `.jsx`, `.js` files in `frontend/src/`

**Action**: Invoke `/code-review` when ready.

---

### 2. Post-Edit Commit Suggestion
**File**: `hooks/post-commit-suggest.md`

**Behavior**: After you make changes to backend or frontend files, the system reminds you to create a structured commit.

**Triggers**:
- After editing/writing files in `backend/` or `frontend/`
- When you have unstaged changes

**Action**: Invoke `/commit` when ready.

---

### 3. Frontend Design Auto-Activation Notice
**File**: `hooks/post-frontend-design-note.md`

**Behavior**: When you create React components, the system automatically invokes `/frontend-design`.

**Triggers**:
- Writing `.tsx` or `.jsx` files in `frontend/src/components/`
- Writing `.tsx` or `.jsx` files in `frontend/src/pages/`

**Action**: None needed - auto-activates.

---

### 4. UI/UX Pro Max Auto-Activation
**File**: `hooks/post-ui-ux-auto-activate.md`

**Behavior**: After creating/editing UI files, suggests using `ui-ux-pro-max` skill for design guidance.

**Triggers**:
- Writing/editing `.tsx`, `.jsx`, `.html` files in frontend

**Action**: The skill auto-searches design databases when you describe UI work.

---

## MCP Servers

### Laravel MCP Server
**Location**: `mcp/laravel-mcp-server/`

Custom MCP server for Laravel integration providing:

| Tool | Description |
|------|-------------|
| `inspect_database_schema` | Query database tables, columns, types |
| `list_routes` | List Laravel routes with controllers/middleware |
| `run_tinker` | Execute PHP code in Laravel context |

**Configuration**: See `/.mcp.json` at project root.

**Status**: Built and ready. Requires Laravel backend at `./backend/` (Phase 1A).

---

## Skills

### ui-ux-pro-max
**Location**: `skills/ui-ux-pro-max/`

Design intelligence database with searchable data:

| Domain | Content |
|--------|---------|
| Products | ~30 product type recommendations |
| Styles | 50 UI styles (glassmorphism, minimalism, etc.) |
| Colors | 21 industry-specific palettes |
| Typography | 50 Google Font pairings |
| Charts | 20 dashboard chart types |
| UX Guidelines | Best practices and principles |
| Stacks | 8 tech stacks (React, Next.js, Vue, etc.) |

**Usage**:
```bash
# Search by domain
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "dashboard" --domain product

# Search by stack
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "component" --stack react
```

---

## Quick Reference

| Component | Status | Purpose |
|-----------|--------|---------|
| `hooks/` | ✅ Active | Event-driven workflow suggestions |
| `mcp/laravel-mcp-server/` | ✅ Ready | Laravel database/routes/Tinker access |
| `skills/ui-ux-pro-max/` | ✅ Active | Design intelligence and search |

---

## Related Documentation

- **CLAUDE.md** - Main project guidelines, workflows, commands
- **DEVELOPMENT_PLAN.md** - Full technical plan and architecture
- **.mcp.json** - MCP server configuration
