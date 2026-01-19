# Decision Trees & Quick Reference

## Decision Tree: Code Smell → Refactor

```
Code smell detected?
│
├─ Long method (>30 lines) ──> laravel-001-extract-method / react-001-extract-component
│
├─ Large class (>300 lines) ──> laravel-002-extract-service / react-002-extract-hook
│
├─ Fat controller ──> laravel-002-extract-service
│
├─ Duplicate code ──> laravel-003-extract-trait / react-003-extract-utility
│
├─ Prop drilling (>3 levels) ──> react-004-context-pattern
│
├─ Complex conditionals ──> pattern-001-strategy-pattern
│
├─ N+1 queries ──> db-001-eager-loading
│
├─ Slow query ──> db-002-query-optimization
│
└─ Complex state ──> react-005-state-refactor
```

## Decision Tree: Should I Refactor Now?

```
Should I refactor?
│
├─ Tests exist and pass? ─── No ──> Write tests first
│         │
│        Yes
│         │
├─ Clear goal defined? ─── No ──> Define goal first
│         │
│        Yes
│         │
├─ Scope limited? ─── No ──> Break into smaller parts
│         │
│        Yes
│         │
├─ Time available? ─── No ──> Document for later
│         │
│        Yes
│         │
└─ Proceed with refactor
```

## Quick Reference: Common Refactors

### Laravel Refactors

| Smell | Refactor | Impact |
|-------|----------|--------|
| Fat Controller | Extract to Service | HIGH |
| Long Method | Extract Method | MEDIUM |
| Repeated Code | Extract Trait | MEDIUM |
| Complex Validation | FormRequest | MEDIUM |
| Raw SQL | Eloquent | LOW |

### React Refactors

| Smell | Refactor | Impact |
|-------|----------|--------|
| Large Component | Extract Component | HIGH |
| Duplicate Logic | Extract Hook | HIGH |
| Prop Drilling | Context/Zustand | HIGH |
| Complex State | Reducer Pattern | MEDIUM |
| Inline Styles | CVA/Tailwind | LOW |

### Database Refactors

| Smell | Refactor | Impact |
|-------|----------|--------|
| N+1 Queries | Eager Loading | HIGH |
| Slow Query | Add Index | HIGH |
| Missing FK | Add Constraint | MEDIUM |
| Denormalize | Computed Column | MEDIUM |

## Safe Refactor Checklist

```
Before:
[ ] Tests pass
[ ] Git clean
[ ] Understand code
[ ] Goal defined

During:
[ ] Small steps
[ ] Test after each step
[ ] Commit on success

After:
[ ] All tests pass
[ ] No behavior change
[ ] Code cleaner
[ ] Document changes
```

## Commit Message Template

```
refactor(scope): description

BEFORE:
- What was wrong

AFTER:
- What is better

VERIFICATION:
- Tests pass
- No behavior change
```

## Metrics to Track

| Metric | Before | After | Goal |
|--------|--------|-------|------|
| Lines of code | X | Y | Same or less |
| Cyclomatic complexity | X | Y | Decrease |
| Test coverage | X% | Y% | Same or better |
| Duplication | X% | Y% | Decrease |

## When NOT to Refactor

- Feature deadline approaching
- No tests exist
- Don't understand the code
- Refactor for refactor's sake
- Premature optimization
- Code will be deleted soon

## Refactor Patterns Summary

### Extract Method
```php
// Before: Long method
function process() {
    // 50 lines of code
}

// After: Multiple focused methods
function process() {
    $this->validate();
    $this->transform();
    $this->save();
}
```

### Extract Service
```php
// Before: Fat controller
class BotController {
    public function store(Request $request) {
        // 100 lines of business logic
    }
}

// After: Thin controller + Service
class BotController {
    public function store(BotRequest $request, BotService $service) {
        return $service->create($request->validated());
    }
}
```

### Extract Hook (React)
```tsx
// Before: Duplicate logic
function Component1() {
    const [data, setData] = useState();
    useEffect(() => { fetchData(); }, []);
}
function Component2() {
    const [data, setData] = useState();
    useEffect(() => { fetchData(); }, []);
}

// After: Shared hook
function useFetchData() {
    const [data, setData] = useState();
    useEffect(() => { fetchData(); }, []);
    return data;
}
```

### Extract Component (React)
```tsx
// Before: Large component
function Page() {
    return (
        <div>
            {/* 200 lines of JSX */}
        </div>
    );
}

// After: Composed components
function Page() {
    return (
        <div>
            <Header />
            <Content />
            <Footer />
        </div>
    );
}
```
