---
name: dead-code
description: |
  Dead code detection specialist for Laravel 12 + React 19. Finds unused files, exports, dependencies, classes, methods, routes, and config.
  Triggers: 'dead code', 'unused', 'cleanup', 'knip', 'unused imports', 'unused files'.
  Use when: cleaning up codebase, before major refactoring, periodic maintenance, reducing bundle size.
allowed-tools:
  - Bash(npx knip*)
  - Bash(cd frontend*)
  - Bash(cd backend*)
  - Bash(php artisan route:list*)
  - Bash(composer show*)
  - Read
  - Grep
  - Glob
  - Edit
context:
  - path: frontend/
  - path: backend/app/
---

# Dead Code Detection Skill

Comprehensive dead code analysis for BotFacebook (Laravel 12 + React 19).

## Quick Start

```
/dead-code              # Full scan (frontend + backend)
/dead-code frontend     # Frontend only (knip)
/dead-code backend      # Backend only (grep analysis)
/dead-code fix          # Scan + auto-fix safe removals
```

## Workflow

### Phase 1: Frontend Analysis (knip)

```bash
cd frontend && npx knip --reporter compact
```

**knip detects:**
- Unused files (not imported anywhere)
- Unused exports (exported but never imported)
- Unused dependencies (in package.json but never used)
- Unused devDependencies
- Unused types/interfaces

**Safe to remove:** unused exports, unused dependencies
**Verify first:** unused files (may be lazy-loaded or dynamic)

### Phase 2: Backend Analysis (grep-based)

Run these checks sequentially:

#### 2a. Unused Services
```
1. Glob all files in app/Services/*.php
2. For each Service class name, Grep across app/ for usage
3. Exclude the service file itself
4. Services not referenced anywhere = likely unused
```

#### 2b. Unused Models
```
1. Glob all files in app/Models/*.php
2. For each Model, Grep for class name across app/, routes/, config/
3. Check for: use statements, ::class references, relationship methods
4. Models not referenced = likely unused
```

#### 2c. Unused Jobs
```
1. Glob all files in app/Jobs/*.php
2. For each Job, Grep for dispatch() or ::class references
3. Jobs never dispatched = likely unused
```

#### 2d. Unused Controllers
```
1. Glob all files in app/Http/Controllers/**/*.php
2. Cross-reference with routes/api.php
3. Controllers not in routes = likely unused
```

#### 2e. Unused Config Keys
```
1. Read config files (config/*.php)
2. For each config key, Grep for config('key') usage
3. Unused config keys = candidates for removal
```

#### 2f. Orphaned Routes
```bash
php artisan route:list --json
```
Cross-reference with existing controllers.

### Phase 3: Report Generation

Output format:

```
## Dead Code Report

### Frontend (knip)
| Type | Count | Files |
|------|-------|-------|
| Unused files | X | file1.ts, file2.ts |
| Unused exports | X | Component.tsx:func |
| Unused deps | X | package-name |

### Backend (grep analysis)
| Type | Count | Items |
|------|-------|-------|
| Unused Services | X | XxxService |
| Unused Models | X | XxxModel |
| Unused Jobs | X | XxxJob |
| Unused Controllers | X | XxxController |

### Recommendations
- SAFE to remove: [items with high confidence]
- VERIFY first: [items that may have dynamic usage]
- DO NOT remove: [items used via config/reflection]
```

## Safety Rules

### Laravel Dynamic Usage Patterns (DO NOT flag as unused)
```php
// Service Container resolution
app(SomeService::class)
resolve(SomeService::class)
$this->app->make(SomeService::class)

// Config-based class references
config('services.some_class')

// Event/Job dispatch
SomeJob::dispatch()
event(new SomeEvent())

// Polymorphic relations
'morphMap' => ['type' => Model::class]

// Middleware references
->middleware('some.middleware')
```

### Frontend Dynamic Usage Patterns (DO NOT flag as unused)
```typescript
// Lazy loading
React.lazy(() => import('./Component'))
const mod = await import('./module')

// Route-based code splitting
{ path: '/x', component: lazy(() => import('./Page')) }
```

## Integration with refactor-cleaner

After generating report, use the local `refactor` skill for:
- Automated safe removal of confirmed dead code
- Running knip, depcheck, ts-prune analysis
- Consolidating duplicated code found during analysis

## Fix Mode

When called with `fix` argument:
1. Run full scan
2. Filter only HIGH confidence items (no dynamic usage possible)
3. For each item:
   - Show the item and confidence level
   - Ask user confirmation before removing
4. After removal, verify:
   - `cd frontend && npx tsc --noEmit` (TypeScript check)
   - `cd backend && php artisan test` (Laravel tests)
