---
name: code-review
description: |
  Comprehensive code reviewer combining quality, security, and API design checks. Reviews against best practices, OWASP Top 10, RESTful conventions.
  Triggers: 'review', 'check code', 'security audit', 'before commit', 'PR review'.
  Use when: reviewing code before commit, auditing existing code, checking for security issues.
allowed-tools:
  - Bash(git diff*)
  - Bash(grep*)
  - Bash(php artisan route:list*)
  - Bash(python3 *.py*)
  - Read
  - Grep
context:
  - path: .php-cs-fixer.php
  - path: eslint.config.js
---

# Code Review Skill

Comprehensive code review for BotFacebook.

## Quick Start

1. **Before Commit:**
   ```bash
   git diff --staged  # Review what's staged
   ```
   Then use this skill to check for issues

2. **Review Types:**
   - Security: OWASP Top 10, injection, auth bypass
   - API Design: RESTful conventions, response format
   - Code Quality: PSR-12, type declarations, naming

3. **Quick Checks:**
   - No `console.log`/`dd()` left in code
   - No hardcoded credentials or API keys
   - Proper error handling
   - TypeScript strict compliance
   - Verify TypeScript interface matches Laravel model `$fillable`/`$casts` (e.g., `BotSettings` in `api.ts` vs `BotSetting.php`)
   - Only changes directly related to the task? No unrelated refactoring? (see CLAUDE.md Minimal Change Principle)

## MCP Tools Available

- **context7**: `query-docs` - Get latest framework patterns
- **sentry**: `search_issues`, `get_issue_details` - Find security issues and errors
- **claude-mem**: `search`, `get_observations` - Search past bugs and patterns

## Memory Search (Before Starting)

**Always search memory first** to find past bugs, security issues, and patterns to avoid.

### Recommended Searches

```
# Search for past bugs in this area
search(query="bug fix", project="bot-fb", type="bugfix", limit=5)

# Find gotchas and problem-solutions
search(query="code review issue", project="bot-fb", concepts=["gotcha", "problem-solution"], limit=5)

# Search for security issues
search(query="security vulnerability", project="bot-fb", type="bugfix", limit=5)
```

### Search by Scenario

| Scenario | Search Query |
|----------|--------------|
| Reviewing auth code | `search(query="auth security", project="bot-fb", concepts=["gotcha"], limit=5)` |
| Reviewing API endpoints | `search(query="API validation", project="bot-fb", type="bugfix", limit=5)` |
| Checking for N+1 | `search(query="N+1 query", project="bot-fb", concepts=["problem-solution"], limit=5)` |
| Reviewing frontend | `search(query="React bug", project="bot-fb", type="bugfix", limit=5)` |

### Using Search Results

1. Run relevant searches based on code being reviewed
2. Use `get_observations(ids=[...])` for full details on similar past bugs
3. Check if current code has similar issues to past bugs
4. Apply learnings to prevent regression

## Key Review Patterns

| Area | Good | Bad |
|------|------|-----|
| Controller | Thin + Service | Fat with logic |
| Validation | FormRequest | `$request->all()` |
| Response | Resource class | Direct model |
| React | useQuery + loading | useState + useEffect |
| Error | Proper handling | Swallowed/missing |

**Full examples:** See [CODE_EXAMPLES.md](CODE_EXAMPLES.md)

## Key Files to Review

### Backend

| File Pattern | What to Check |
|--------------|---------------|
| `app/Http/Controllers/*.php` | Thin controllers, uses services |
| `app/Http/Requests/*.php` | Validation rules complete |
| `app/Services/*.php` | Business logic, transactions |
| `app/Models/*.php` | Relationships, $fillable, casts |
| `routes/api.php` | RESTful, middleware applied |
| `database/migrations/*.php` | Indexes, foreign keys |
| `config/*.php` | No `env()` calls (use config) |

### Frontend

| File Pattern | What to Check |
|--------------|---------------|
| `src/components/*.tsx` | Props typed, no any |
| `src/hooks/*.ts` | Error handling, loading states |
| `src/stores/*.ts` | Proper Zustand patterns |
| `src/lib/api.ts` | Error handling, types |
| `src/pages/*.tsx` | Suspense boundaries |

## Quick Commands

```bash
# Pre-commit checks
git diff --staged
./vendor/bin/pint --test && npm run lint
php artisan test --filter=Unit && npm run type-check

# Security audit
grep -rn "DB::raw\|whereRaw" app/ --include="*.php"
composer audit
```

## Detailed Guides

| Guide | Purpose |
|-------|---------|
| [SECURITY_CHECKLIST.md](SECURITY_CHECKLIST.md) | OWASP, auth, injection |
| [API_CHECKLIST.md](API_CHECKLIST.md) | RESTful, responses, codes |
| [CODE_QUALITY.md](CODE_QUALITY.md) | PSR-12, types, patterns |
| [CODE_EXAMPLES.md](CODE_EXAMPLES.md) | Good/bad code examples |

## Common Issues

| Issue | Fix |
|-------|-----|
| `dd()` left | Remove or use logging |
| Missing validation | Add FormRequest |
| N+1 queries | Use `->with()` |
| Missing types | Add type hints |
| Fat controller | Extract to service |
| TS interface out of sync with Laravel model | Compare `$fillable`/`$casts` with TypeScript interface fields |
| Unrelated changes in PR | Revert; follow Minimal Change Principle (CLAUDE.md) |

## Output Format

Report as: Summary → Critical 🔴 → Warnings 🟡 → Suggestions 🟢 → Approved ✅

## Usage

Run `/code-review` before commit or PR.
