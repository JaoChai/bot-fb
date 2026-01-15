---
name: code-review
description: Comprehensive code reviewer combining quality, security, and API design checks. Reviews against best practices, OWASP Top 10, RESTful conventions. Use before committing code, after major changes, or when auditing existing code.
---

# Code Review Skill

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

## MCP Tools Available

- **context7**: `query-docs` - Get latest framework patterns
- **sentry**: `search_issues`, `get_issue_details` - Find security issues and errors

## Detailed Guides

- **Security:** See [SECURITY_CHECKLIST.md](SECURITY_CHECKLIST.md)
- **API Design:** See [API_CHECKLIST.md](API_CHECKLIST.md)
- **Code Quality:** See [CODE_QUALITY.md](CODE_QUALITY.md)

## Review Workflow

### 1. Gather Changes
```bash
git diff --staged           # Staged changes
git diff HEAD~1             # Last commit
git log --oneline -10       # Recent commits
```

### 2. Security Scan
Check all files for:
- SQL injection (raw queries)
- XSS (unescaped output)
- Auth bypass (missing middleware)
- Sensitive data exposure

### 3. API Design Review
For route/controller changes:
- RESTful naming conventions
- Consistent response format
- Proper HTTP status codes
- Rate limiting applied

### 4. Code Quality Check
For all PHP/TypeScript:
- PSR-12 / ESLint compliance
- Type declarations
- No dead code
- Meaningful variable names

## Common Issues Found

| Issue | Fix |
|-------|-----|
| `dd()` left in code | Remove or use proper logging |
| Missing validation | Add FormRequest validation |
| N+1 queries | Use eager loading with `->with()` |
| Hardcoded URLs | Use config/env variables |
| Missing types | Add TypeScript/PHP type hints |

## Integration with Pre-Commit

Hook suggests this skill before git commit. Run review manually:
```
/code-review
```
