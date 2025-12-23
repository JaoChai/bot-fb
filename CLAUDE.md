# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Status

This is a new/empty repository. The project structure and codebase have not been established yet.

## Quick Reference - Short Codes

### Core Workflow
- `ccc` - Create context issue and compact the conversation
- `nnn` - Smart planning: Auto-runs `ccc` if no recent context → Create detailed implementation plan (research only, no coding)
- `gogogo` - Execute the most recent plan issue step-by-step
- `lll` - List project status (issues, PRs, commits)
- `rrr` - Create detailed session retrospective

### First Task Pattern
1. Run `lll` to see current project status
2. Run `nnn` to analyze and create a plan
3. Use `gogogo` to implement the plan

## Critical Safety Rules

### Command Usage
- **NEVER use `-f` or `--force` flags with any commands**
- Always use safe, non-destructive command options

### Git Operations
- Never use `git push --force` or `git push -f`
- Never use `git checkout -f`
- Never use `git clean -f`
- **NEVER MERGE PULL REQUESTS WITHOUT EXPLICIT USER PERMISSION**
- **Never use `gh pr merge` unless explicitly instructed by the user**
- Always wait for user review and approval before any merge

### File Operations
- Never use `rm -rf` - use `rm -i` for interactive confirmation
- Always confirm before deleting files

### Package Manager Operations
- Never use `--force` with package managers
- Always review lockfile changes before committing

## Git Commit Format

```
[type]: [brief description]

- What: [specific changes]
- Why: [motivation]
- Impact: [affected areas]

Closes #[issue-number]
```

**Types**: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`

## GitHub Workflow

### Standard Development Flow
```bash
# 1. Create branch from issue
git checkout -b feat/issue-number-description

# 2. Make changes and test thoroughly

# 3. Commit with descriptive message
git add -A
git commit -m "feat: Brief description

- What: Specific changes made
- Why: Motivation for the changes
- Impact: What this affects

Closes #issue-number"

# 4. Push and create PR
git push -u origin branch-name
gh pr create --title "Same as commit" --body "Fixes #issue_number"

# 5. CRITICAL: NEVER MERGE PRs YOURSELF
# ONLY provide PR link to user and wait for explicit merge instruction
```

## Context Management

### Two-Issue Pattern (ccc → nnn)
1. **Context Issues** (`ccc`): Preserve session state and context
2. **Task Issues** (`nnn`): Contain actual implementation plans

This separation ensures clear distinction between context dumps and actionable tasks.

## Retrospective Requirements (rrr)

### Mandatory Sections
- **AI Diary**: First-person narrative of session experience
- **Honest Feedback**: Frank assessment of what worked and what didn't

These sections are **REQUIRED** - never skip them.

### Time Zone
- **PRIMARY: GMT+7 (Bangkok time)** - Always show GMT+7 first
- UTC time included for reference only (in parentheses)

## Lessons Learned

### Planning Patterns
- Use parallel agents for analyzing different aspects of complex systems
- Ask "what's the minimum viable first step?" before comprehensive implementation
- 1-hour implementation chunks are optimal for maintaining focus

### Common Mistakes to Avoid
- Creating overly comprehensive initial plans - Break into 1-hour phases
- Trying to implement everything at once - Start with minimum viable implementation
- Skipping AI Diary and Honest Feedback in retrospectives

### User Preferences (Observed)
- Prefers manageable scope (tasks completable in under 1 hour)
- Values phased approaches
- Appreciates workflow patterns like "ccc nnn gh flow"

## Claude Code Skills & Automation

### Available Skills

#### 1. `/feature-dev` - Architecture Planning
- **When**: Start of new feature/issue work
- **What it does**: Analyzes codebase, plans architecture, decides tech approach
- **Invocation**: Manual - you must type `/feature-dev`
- **Best for**: Complex features, cross-layer changes, important decisions
- **Example**: Starting Issue #11 (Bot API)
  ```bash
  /feature-dev
  # System analyzes and plans the implementation
  ```

#### 2. `/code-review` - Quality Assurance
- **When**: After code is written, before commit
- **What it does**: Checks bugs, security, style, project standards
- **Invocation**: Manual OR Auto-suggested via hook
- **Best for**: All backend/frontend code
- **Example**:
  ```bash
  /code-review
  # Reviews Laravel controllers, React components, etc.
  ```

#### 3. `/pr-review-toolkit:review-pr` - Comprehensive PR Review
- **When**: Before creating Pull Request
- **What it does**: Multi-agent review (code, error handling, types, comments, simplification)
- **Invocation**: Manual - you must type it
- **Best for**: Major features, critical code paths
- **Example**:
  ```bash
  /pr-review-toolkit:review-pr
  # Comprehensive review before PR
  ```

#### 4. `/commit` - Structured Commits
- **When**: Ready to save changes
- **What it does**: Auto-generates properly formatted commit message
- **Invocation**: Manual OR Auto-suggested via hook
- **Best for**: All code changes
- **Example**:
  ```bash
  /commit
  # Creates: [feat]: Bot API endpoints
  # - What: POST/GET/PUT/DELETE endpoints
  # - Why: MVP bot management
  # - Impact: Enables Phase 1C
  # Closes #11
  ```

#### 5. `/frontend-design` - Production UI Components
- **When**: Creating React components
- **What it does**: Auto-polishes UI with shadcn/ui, Tailwind, accessibility
- **Invocation**: AUTO - activates automatically for React files
- **Best for**: All React components
- **Behavior**: You don't invoke this - it auto-activates
- **Example**: Creating Issue #17 upload form
  ```
  You: "Create knowledge base upload component"
  System: Auto-invokes /frontend-design → Production-ready UI
  ```

#### 6. `ui-ux-pro-max` - Design Intelligence Database
- **When**: Creating any UI (dashboard, landing page, chat interface, etc.)
- **What it does**: Searches design database for styles, colors, fonts, UX guidelines
- **Invocation**: AUTO - activates when you describe UI/UX work
- **Database includes**:
  - 50 UI Styles (Glassmorphism, Minimalism, Brutalism, Bento Grid, etc.)
  - 21 Color Palettes (industry-specific)
  - 50 Font Pairings (with Google Fonts imports)
  - 20 Chart Types (for dashboards)
  - 8 Tech Stacks (React, Next.js, Vue, Svelte, SwiftUI, Flutter, etc.)
- **Example**:
  ```
  You: "สร้าง dashboard สำหรับ bot analytics"
  System: Searches product, style, color, typography, chart domains
  Result: Complete design system with proper colors, fonts, charts
  ```
- **Manual Search**:
  ```bash
  # Search for style
  python3 .claude/skills/ui-ux-pro-max/scripts/search.py "chatbot dashboard" --domain product

  # Search for colors
  python3 .claude/skills/ui-ux-pro-max/scripts/search.py "saas" --domain color

  # Search for typography
  python3 .claude/skills/ui-ux-pro-max/scripts/search.py "modern professional" --domain typography
  ```

### Auto-Activation Behaviors

| Skill | When it Auto-Activates | Your Role |
|-------|------------------------|-----------|
| `frontend-design` | Writing `.tsx`/`.jsx` React files | Just describe what you want; system polishes it |
| `ui-ux-pro-max` | Requesting UI/UX work (design, build, create) | Describe what you want; system searches design DB |
| Hooks (code-review suggest) | After editing backend/frontend code | System suggests `/code-review`; you can invoke or skip |
| Hooks (commit suggest) | After code changes | System reminds you to `/commit` |

### MCP Servers (Always Available)

- **GitHub MCP** - Issue/PR management
- **Playwright MCP** - Browser testing
- **Context7 MCP** - Library documentation
- **Greptile MCP** - Codebase analysis
- **Laravel Boost** (recommended install) - DB schema, routes, Tinker

#### MCP Configuration

Project MCP servers are configured in `.mcp.json` at project root:

```json
{
  "mcpServers": {
    "laravel": {
      "command": "node",
      "args": [".claude/mcp/laravel-mcp-server/dist/index.js"],
      "env": {
        "LARAVEL_PATH": "./backend"
      }
    }
  }
}
```

**Custom Laravel MCP Server** - Built in-house at `.claude/mcp/laravel-mcp-server/`:
- Node.js + TypeScript implementation
- Uses `@modelcontextprotocol/sdk` for MCP protocol
- Secure `execFile` for command execution (prevents shell injection)
- See `.claude/mcp/laravel-mcp-server/README.md` for full documentation

#### Using MCP Servers

**GitHub MCP** - Already available, use in conversations:
```
You: "What PRs are open?"
Claude: Uses GitHub MCP to list open PRs
```

**Playwright MCP** - Browser automation for testing:
```
You: "Test the login flow"
Claude: Uses Playwright MCP to automate browser testing
```

**Context7 MCP** - Get up-to-date library docs:
```
You: "Show me React hooks documentation"
Claude: Uses Context7 MCP to fetch latest React docs
```

**Greptile MCP** - Codebase search and analysis:
```
You: "Where are authentication routes defined?"
Claude: Uses Greptile MCP for intelligent codebase search
```

**Laravel Boost MCP** - Database and Laravel introspection:
```
You: "What tables do we have?"
Claude: Uses Laravel Boost MCP to query database schema
```

#### MCP Status (Issue #49)

- ✅ `.mcp.json` configuration created
- ✅ Custom Laravel MCP server built and configured
  - Location: `.claude/mcp/laravel-mcp-server/`
  - Tools: Database introspection, route listing, Tinker execution
  - Security: Uses safe `execFile` instead of shell commands
- ✅ Other MCP servers (GitHub, Playwright, Context7, Greptile) operational
- 📝 See Issue #49 for setup details and findings

---

## Smart Development Workflows

### Workflow: Backend API Feature (e.g., Issue #11 Bot API)

```
┌─ Step 1: Plan Architecture
│  You: /feature-dev
│  System: Analyzes, plans approach
│
├─ Step 2: Write Code
│  You: Create controllers, models, migrations
│  System: (no auto-action yet)
│
├─ Step 3: Code Review (Auto-Suggested or Manual)
│  Hook: "💡 Consider running /code-review"
│  You: /code-review
│  System: Checks quality, security, standards
│
├─ Step 4: Commit (Auto-Suggested)
│  Hook: "📝 Ready to commit?"
│  You: /commit
│  System: Generates formatted commit message
│
└─ Step 5: Create PR
   You: /commit-push-pr
   System: Creates PR with issue link
```

### Workflow: Frontend Component (e.g., Issue #17 KB Upload)

```
┌─ Step 1: Plan Architecture
│  You: /feature-dev
│  System: Analyzes, plans UI structure
│
├─ Step 2: Design Research (AUTO)
│  You: "สร้าง dashboard สำหรับ bot analytics"
│  System: AUTO → ui-ux-pro-max searches design DB
│  Result: Style, colors, typography, UX guidelines
│
├─ Step 3: Write React Component
│  You: Create .tsx component file
│  System: AUTO → /frontend-design activates
│  Result: Production-ready shadcn/ui + Tailwind UI
│
├─ Step 4: Test Component (Optional)
│  You: Use Playwright MCP for E2E testing
│  System: Browser automation testing
│
├─ Step 5: Code Review
│  Hook: "💡 Consider running /code-review"
│  You: /code-review
│  System: Reviews design + code quality
│
├─ Step 6: Commit
│  Hook: "📝 Ready to commit?"
│  You: /commit
│  System: Generates commit message
│
└─ Step 7: Full PR Review
   You: /pr-review-toolkit:review-pr
   System: Comprehensive multi-agent review
   You: /commit-push-pr
   System: Creates PR
```

### Workflow: Database/Migration (e.g., Issue #5 Migrations)

```
┌─ Step 1: Plan Schema
│  You: /feature-dev
│  System: Plans database schema
│
├─ Step 2: Design with Laravel Boost
│  You: Describe schema
│  System: Laravel Boost MCP suggests structure
│
├─ Step 3: Create Migrations
│  You: Create migration files
│  System: (no auto-action)
│
├─ Step 4: Code Review
│  Hook: "💡 Consider running /code-review"
│  You: /code-review
│  System: Validates migrations, indexes, relationships
│
├─ Step 5: Test Migrations
│  You: Run migrations locally, test queries
│
└─ Step 6: Commit
   Hook: "📝 Ready to commit?"
   You: /commit
   System: Generates commit message
```

### Quick Commands Reference

```bash
# Start a new feature
/feature-dev

# Review code quality
/code-review

# Comprehensive PR review
/pr-review-toolkit:review-pr

# Create commit with message
/commit

# Commit + push + create PR
/commit-push-pr

# List hooks configured
/hookify:list
```

---

## Project Context

### Current Status
- **Phase**: Planning phase complete ✅
- **GitHub Issues**: 44 issues created (#1-#44)
- **Tech Stack**: Laravel 12 + React 19 + Neon.tech + pgvector
- **Architecture**: REST API + WebSocket (Reverb) + Vector Search

### Architecture
- **Backend**: Laravel 12 (PHP 8.4) with Sanctum Auth
- **Frontend**: React 19 + Vite + shadcn/ui + Tailwind v4
- **Database**: Neon.tech PostgreSQL + pgvector
- **Real-time**: Laravel Reverb (WebSocket)
- **Cache/Queue**: Redis
- **Storage**: AWS S3

### Important Links
- **GitHub Issues**: https://github.com/JaoChai/bot-fb/issues
- **Index Issue**: #44 - Complete issue navigation
- **Development Plan**: DEVELOPMENT_PLAN.md (full technical details)
- **Hooks Configuration**: .claude/hooks/ directory

### Build Commands
```bash
# Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve

# Frontend
cd frontend
npm install
npm run dev

# Both together (with Docker in Phase 3D)
docker-compose up
```

### Test Commands
```bash
# Laravel tests (Pest)
php artisan test

# React tests (Vitest)
npm run test

# E2E tests (Playwright)
npm run test:e2e
```

### Hooks Configuration
- **Auto Code Review Suggestion**: `.claude/hooks/post-code-review-suggest.md`
- **Auto Commit Suggestion**: `.claude/hooks/post-commit-suggest.md`
- **Frontend Design Notice**: `.claude/hooks/post-frontend-design-note.md`
- **UI/UX Pro Max Auto-Activate**: `.claude/hooks/post-ui-ux-auto-activate.md`

These hooks auto-suggest skills at appropriate times without interrupting workflow.

### UI/UX Pro Max Skill
Located at `.claude/skills/ui-ux-pro-max/` with:
- **SKILL.md**: Main skill definition
- **data/**: Design databases (styles, colors, typography, etc.)
- **scripts/**: Python search scripts
