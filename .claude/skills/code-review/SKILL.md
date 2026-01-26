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

### Backend (Laravel)

```php
// ✅ Good: Service layer pattern
class BotController extends Controller
{
    public function __construct(private BotService $service) {}

    public function store(StoreBotRequest $request): BotResource
    {
        $bot = $this->service->create($request->validated());
        return new BotResource($bot);
    }
}

// ❌ Bad: Fat controller
public function store(Request $request)
{
    $data = $request->all();  // No validation
    $bot = Bot::create($data);  // No service layer
    return $bot;  // No resource transformation
}
```

### Frontend (React)

```typescript
// ✅ Good: Proper hooks usage
function BotList() {
    const { data, isLoading } = useQuery({
        queryKey: ['bots'],
        queryFn: () => api.getBots(),
    });

    if (isLoading) return <Skeleton />;
    return <List items={data} />;
}

// ❌ Bad: Missing loading/error states
function BotList() {
    const [bots, setBots] = useState([]);
    useEffect(() => {
        api.getBots().then(setBots);  // No error handling
    }, []);
    return <List items={bots} />;
}
```

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

## Common Tasks

### 1. Pre-Commit Review

```bash
# Check what's staged
git diff --staged

# Run linters
cd backend && ./vendor/bin/pint --test
cd frontend && npm run lint

# Run tests
php artisan test --filter=Unit
npm run type-check
```

### 2. PR Review Checklist

```markdown
## Code Quality
- [ ] No console.log/dd() left
- [ ] Types complete (no `any`)
- [ ] Error handling present
- [ ] Loading states handled

## Security
- [ ] Input validated
- [ ] Auth middleware applied
- [ ] No SQL injection risks
- [ ] Sensitive data not logged

## API Design
- [ ] RESTful naming
- [ ] Consistent response format
- [ ] Proper status codes
- [ ] Rate limiting applied

## Performance
- [ ] No N+1 queries
- [ ] Indexes for new columns
- [ ] Pagination for lists
```

### 3. Security Audit

```bash
# Check for hardcoded secrets
grep -rn "password\s*=\s*['\"]" app/ --include="*.php"
grep -rn "api_key\|secret" app/ --include="*.php"

# Check for SQL injection
grep -rn "DB::raw\|whereRaw" app/ --include="*.php"

# Check middleware coverage
php artisan route:list --columns=uri,middleware | grep -v "auth"

# Check composer vulnerabilities
composer audit
```

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
| No error handling | Add try-catch, error boundaries |
| Fat controller | Extract to service layer |
| Prop drilling | Use custom hook or context |

## Detailed Guides

- **Security:** See [SECURITY_CHECKLIST.md](SECURITY_CHECKLIST.md)
- **API Design:** See [API_CHECKLIST.md](API_CHECKLIST.md)
- **Code Quality:** See [CODE_QUALITY.md](CODE_QUALITY.md)

## Review Output Format

```markdown
## Code Review Report

### Summary
- Files reviewed: X
- Issues found: X (Critical: X, Warning: X, Info: X)

### Critical Issues 🔴
1. [FILE:LINE] Description
   - Problem: ...
   - Fix: ...

### Warnings 🟡
1. [FILE:LINE] Description

### Suggestions 🟢
1. [FILE:LINE] Description

### Approved ✅
- List of files with no issues
```

## Integration with Pre-Commit

Hook suggests this skill before git commit. Run review manually:
```
/code-review
```
