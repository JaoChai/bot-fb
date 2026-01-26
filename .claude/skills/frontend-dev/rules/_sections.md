# Rule Sections & Decision Trees

## Section Organization

Rules are organized by priority within each section. CRITICAL rules should be checked first.

### Priority Order

1. **CRITICAL** - Must fix immediately, blocks deployment
2. **HIGH** - Should fix before PR merge
3. **MEDIUM** - Fix when touching related code
4. **LOW** - Nice to have, fix when convenient

---

## Decision Trees

### When to Use useMemo vs useCallback

```
Need to memoize something?
├── Is it a function?
│   ├── YES → useCallback
│   └── NO → Is it an expensive computation?
│       ├── YES → useMemo
│       └── NO → Don't memoize (premature optimization)
│
├── Is the component re-rendering too often?
│   ├── YES → Profile first, then:
│   │   ├── Child components re-rendering? → React.memo + useCallback for callbacks
│   │   └── Expensive calculations? → useMemo
│   └── NO → Don't memoize yet
│
└── Rule of thumb: Measure before optimizing
```

### State Location Decision

```
Where should this state live?
├── Used by single component?
│   └── useState (local)
│
├── Shared across sibling components?
│   ├── Lift to common parent
│   └── Or use Zustand for complex cases
│
├── Needed across many unrelated components?
│   ├── Server state (from API)? → React Query
│   └── Client state? → Zustand
│
├── URL-dependent?
│   └── URL params (useSearchParams, useParams)
│
└── Form state?
    └── React Hook Form or useActionState (React 19)
```

### Query vs Mutation

```
What operation are you doing?
├── Reading data?
│   ├── One-time fetch → useQuery
│   ├── Paginated list → useInfiniteQuery
│   └── Dependent on other query → useQuery with enabled
│
├── Creating/Updating/Deleting?
│   ├── Simple mutation → useMutation + invalidateQueries
│   ├── Need instant UI feedback → useMutation + optimistic update
│   └── Multiple related updates → useMutation + setQueryData
│
└── Need both?
    └── useQuery for display + useMutation for changes
```

### Error Handling Strategy

```
Where did the error occur?
├── API call failed?
│   ├── Query error → Show inline error or error boundary
│   ├── Mutation error → Toast notification + keep form data
│   └── Auth error (401) → Redirect to login
│
├── Runtime error?
│   ├── In component render? → Error boundary catches it
│   ├── In event handler? → try/catch + toast
│   └── In async operation? → try/catch + error state
│
└── Validation error?
    └── Form validation → Inline field errors
```

### Component File Location

```
Where should this component live?
├── Reusable UI primitive (button, input, card)?
│   └── src/components/ui/
│
├── Feature-specific component?
│   ├── Used only in one page → src/pages/[page]/components/
│   └── Used across feature → src/components/[feature]/
│
├── Layout component (header, sidebar)?
│   └── src/components/layout/
│
├── Shared across features?
│   └── src/components/shared/
│
└── Page component?
    └── src/pages/
```

---

## Section Index

### React (react-*)
| Rule | Impact | Description |
|------|--------|-------------|
| react-001 | HIGH | Component structure and props |
| react-002 | MEDIUM | 'use client' directive placement |
| react-003 | HIGH | use() hook for promises |
| react-004 | HIGH | useActionState for forms |
| react-005 | MEDIUM | useOptimistic for instant feedback |
| react-006 | CRITICAL | Error boundaries |
| react-007 | HIGH | Suspense boundaries |
| react-008 | MEDIUM | Generic components with TypeScript |

### React Query (query-*)
| Rule | Impact | Description |
|------|--------|-------------|
| query-001 | CRITICAL | Query keys factory pattern |
| query-002 | HIGH | enabled option for conditional fetching |
| query-003 | HIGH | Optimistic updates |
| query-004 | HIGH | Cache invalidation strategies |
| query-005 | MEDIUM | Infinite queries for pagination |
| query-006 | MEDIUM | Prefetching on hover/focus |
| query-007 | HIGH | Error handling in queries |

### State (state-*)
| Rule | Impact | Description |
|------|--------|-------------|
| state-001 | MEDIUM | Zustand persist middleware |
| state-002 | HIGH | Store selectors for performance |
| state-003 | MEDIUM | Avoiding prop drilling |

### Performance (perf-*)
| Rule | Impact | Description |
|------|--------|-------------|
| perf-001 | HIGH | Memoization guidelines |
| perf-002 | MEDIUM | Code splitting with lazy |
| perf-003 | MEDIUM | Virtualization for long lists |
| perf-004 | CRITICAL | Stable references in deps |
| perf-005 | HIGH | Bundle size monitoring |
| perf-006 | HIGH | Functional setState for stale closures |
| perf-007 | HIGH | Derive state in render, not effects |
| perf-008 | MEDIUM | content-visibility for long lists |

### Accessibility (a11y-*)
| Rule | Impact | Description |
|------|--------|-------------|
| a11y-001 | HIGH | Semantic HTML elements |
| a11y-002 | HIGH | Keyboard navigation |
| a11y-003 | MEDIUM | ARIA labels and roles |
| a11y-004 | MEDIUM | Color contrast requirements |

### Styling (style-*)
| Rule | Impact | Description |
|------|--------|-------------|
| style-001 | MEDIUM | cn() utility usage |
| style-002 | MEDIUM | CVA variants pattern |
| style-003 | MEDIUM | Tailwind class conflicts |

### TypeScript (ts-*)
| Rule | Impact | Description |
|------|--------|-------------|
| ts-001 | HIGH | Avoid any type |
| ts-002 | MEDIUM | Discriminated unions |
| ts-003 | HIGH | API response types |

### Gotchas (gotcha-*)
| Rule | Impact | Description |
|------|--------|-------------|
| gotcha-001 | CRITICAL | response.data access |
| gotcha-002 | CRITICAL | Infinite re-renders |
| gotcha-003 | HIGH | Echo auth token |
| gotcha-004 | MEDIUM | Modal event bubbling |
| gotcha-005 | MEDIUM | Form submit button type |

### Async Patterns (async-*) - NEW
| Rule | Impact | Description |
|------|--------|-------------|
| async-001 | CRITICAL | Parallel data fetching with Promise.all |
| async-002 | CRITICAL | Avoid sequential awaits in loops |
| async-003 | HIGH | Preload data before navigation |

### Bundle Optimization (bundle-*) - NEW
| Rule | Impact | Description |
|------|--------|-------------|
| bundle-001 | CRITICAL | Import from source, not barrel files |
| bundle-002 | HIGH | Ensure proper tree-shaking |

### JavaScript Performance (js-*) - NEW
| Rule | Impact | Description |
|------|--------|-------------|
| js-001 | MEDIUM | Avoid layout thrashing |
| js-002 | LOW-MEDIUM | Use Map for repeated lookups |
| js-003 | LOW-MEDIUM | Use immutable array methods
