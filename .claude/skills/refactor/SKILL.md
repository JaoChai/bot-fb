---
name: refactor
description: Code refactoring specialist for Laravel 12 + React 19 + PostgreSQL. Handles Extract Method, Service extraction, component decomposition, query optimization refactoring. Use when cleaning up technical debt, improving code structure, or preparing for new features.
---

# Refactor Skill

Safe, incremental code refactoring for BotFacebook stack.

## Quick Start

1. **Identify Scope:**
   ```bash
   git status                 # What files to refactor
   php artisan test           # Ensure tests pass first
   ```

2. **Refactor Types:**
   - Extract: Method, Class, Service, Component
   - Rename: Variables, methods, classes
   - Move: Relocate code to proper location
   - Simplify: Reduce complexity, remove duplication

3. **Golden Rules:**
   - Tests must pass before AND after
   - One refactor type per commit
   - No behavior changes during refactor

## MCP Tools Available

- **Context7:** `/query-docs` for latest patterns
- **Neon:** `run_sql` for DB schema analysis
- **Sentry:** Check for related errors
- **claude-mem**: `search`, `get_observations` - Search past decisions and trade-offs

## Memory Search (Before Starting)

**Always search memory first** to find past decisions, why code was structured this way, and trade-offs made.

### Recommended Searches

```
# Search for past refactoring decisions
search(query="refactor decision", project="bot-fb", type="refactor", limit=5)

# Find why code exists this way
search(query="code structure reason", project="bot-fb", concepts=["why-it-exists", "trade-off"], limit=5)

# Search for patterns used
search(query="architecture pattern", project="bot-fb", concepts=["pattern"], limit=5)
```

### Search by Scenario

| Scenario | Search Query |
|----------|--------------|
| Refactoring a service | `search(query="service extraction", project="bot-fb", type="refactor", limit=5)` |
| Changing component structure | `search(query="React component refactor", project="bot-fb", concepts=["pattern"], limit=5)` |
| Database optimization | `search(query="query optimization", project="bot-fb", type="refactor", limit=5)` |
| Understanding existing code | `search(query="why this code", project="bot-fb", concepts=["why-it-exists"], limit=5)` |

### Using Search Results

1. Run relevant searches before changing code structure
2. Use `get_observations(ids=[...])` for full context on past decisions
3. Understand why previous developers made certain choices
4. Avoid repeating past mistakes or undoing intentional trade-offs

## Refactor Decision Tree

```
Code smell detected?
├── Long method (>30 lines)     → Extract Method/Service
├── Large class (>300 lines)    → Extract Class/Trait
├── Duplicate code              → Extract to shared function
├── Complex conditionals        → Strategy/State pattern
├── N+1 queries                 → Eager loading refactor
├── Prop drilling (React)       → Context/Custom hook
└── Fat controller              → Service layer extraction
```

## Detailed Guides

- **Laravel:** See [LARAVEL_REFACTOR.md](LARAVEL_REFACTOR.md)
- **React:** See [REACT_REFACTOR.md](REACT_REFACTOR.md)
- **Database:** See [DB_REFACTOR.md](DB_REFACTOR.md)

## Pre-Refactor Checklist

- [ ] All tests passing?
- [ ] Git working tree clean?
- [ ] Understand what code does?
- [ ] Have a clear goal?
- [ ] Backup plan if things break?

## Safe Refactor Workflow

### 1. Prepare
```bash
git checkout -b refactor/description
php artisan test                    # Must pass
npm run type-check                  # Must pass
```

### 2. Refactor (small steps)
```bash
# Make ONE change
# Run tests
# Commit if pass
# Repeat
```

### 3. Verify
```bash
php artisan test                    # Must pass
npm run type-check                  # Must pass
git diff main                       # Review all changes
```

## Common Refactors

### Laravel

| Smell | Refactor | Example |
|-------|----------|---------|
| Fat Controller | Extract Service | Controller → Service |
| Repeated queries | Query Scope | Model scopes |
| Complex validation | FormRequest | Request classes |
| God class | Extract Trait/Class | Split responsibilities |
| Raw SQL | Eloquent | Query builder |

### React

| Smell | Refactor | Example |
|-------|----------|---------|
| Prop drilling | Custom Hook/Context | useAuth hook |
| Large component | Extract Component | Split UI parts |
| Duplicate logic | Custom Hook | useApi hook |
| Complex state | Reducer/Zustand | State management |
| Inline styles | Tailwind classes | CSS refactor |

### Database

| Smell | Refactor | Example |
|-------|----------|---------|
| Missing index | Add Index | Migration |
| N+1 queries | Eager loading | with() |
| Slow query | Query optimization | Indexes/limits |
| Denormalize | Add computed column | Performance |

## Anti-Patterns (Don't Do)

| Bad | Why | Do Instead |
|-----|-----|------------|
| Refactor + feature | Hard to debug | Separate commits |
| Big bang refactor | High risk | Small increments |
| No tests first | Can't verify | Write tests first |
| Rename everything | Breaking changes | Gradual rename |
| Premature optimization | Waste time | Profile first |

## Metrics to Track

| Before Refactor | After Refactor |
|-----------------|----------------|
| Lines of code | Should decrease or stay same |
| Cyclomatic complexity | Should decrease |
| Test coverage | Should stay same or increase |
| Response time | Should stay same or improve |

## Testing After Refactor

```bash
# Backend
php artisan test
php artisan test --coverage

# Frontend
npm run type-check
npm run test

# Integration
npm run test:e2e
```

## Commit Message Format

```
refactor(scope): description

- What was refactored
- Why it was refactored
- No behavior changes

Examples:
refactor(bot-service): extract message handling to dedicated service
refactor(chat-list): split into smaller components
refactor(queries): add indexes for slow bot lookups
```

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/*.php` | Service layer (refactor targets) |
| `app/Http/Controllers/*.php` | Controllers (should be thin) |
| `app/Models/*.php` | Models (scopes, relationships) |
| `src/components/*.tsx` | React components |
| `src/hooks/*.ts` | Custom hooks |
| `src/stores/*.ts` | Zustand stores |

## Gotchas

| Problem | Cause | Solution |
|---------|-------|----------|
| Tests fail after refactor | Behavior changed | Refactor should not change behavior |
| Import errors | Circular dependencies | Extract shared code to separate file |
| Type errors | Missing types after move | Update all imports and types |
| IDE not updating | Cached references | Restart IDE, clear cache |
| Merge conflicts | Large diff | Smaller, incremental refactors |

## Utility Scripts

- `scripts/find_code_smells.sh` - Find potential refactor candidates
