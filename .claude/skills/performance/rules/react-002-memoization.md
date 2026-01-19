---
id: react-002-memoization
title: React Memoization Patterns
impact: MEDIUM
impactDescription: "Improper use of useMemo, useCallback, and React.memo"
category: react
tags: [react, memo, useMemo, useCallback, optimization]
relatedRules: [react-001-re-renders, react-003-virtualization]
---

## Symptom

- Over-memoization (everything wrapped in memo)
- Memoization not working (wrong dependencies)
- Memory leaks from stale closures
- Premature optimization

## Root Cause

1. Memoizing cheap operations
2. Wrong or missing dependencies
3. Not understanding when memo helps
4. Memoizing at wrong level
5. Not profiling first

## Diagnosis

### Quick Check

```tsx
// Check if memoization is helping
const expensive = useMemo(() => {
  console.time('expensive');
  const result = calculateExpensiveValue(data);
  console.timeEnd('expensive');
  return result;
}, [data]);
// If < 1ms, probably not worth memoizing
```

### Detailed Analysis

```tsx
// Profile with React DevTools
// Profiler > Settings > Record why each component rendered

// Check memo effectiveness
const MemoizedChild = React.memo(Child, (prevProps, nextProps) => {
  console.log('Comparing props:', prevProps, nextProps);
  return prevProps.id === nextProps.id;
});
```

## Solution

### Fix Steps

1. **When to use useMemo**
```tsx
// ✓ Good: Expensive computation
const sortedItems = useMemo(() => {
  return items.slice().sort((a, b) => a.name.localeCompare(b.name));
}, [items]);

// ✓ Good: Referential equality for dependency
const filter = useMemo(() => ({ status: 'active', type }), [type]);
useEffect(() => {
  fetchData(filter);
}, [filter]);

// ✗ Bad: Cheap operation
const fullName = useMemo(() => {
  return `${firstName} ${lastName}`;
}, [firstName, lastName]);
// Just use: const fullName = `${firstName} ${lastName}`;
```

2. **When to use useCallback**
```tsx
// ✓ Good: Passed to memoized child
const MemoizedList = memo(List);

function Parent() {
  const handleSelect = useCallback((id: string) => {
    setSelected(id);
  }, []);

  return <MemoizedList onSelect={handleSelect} />;
}

// ✓ Good: Used in dependency array
const fetchData = useCallback(async () => {
  const data = await api.get('/data');
  setData(data);
}, []);

useEffect(() => {
  fetchData();
}, [fetchData]);

// ✗ Bad: Not passed to memo or used as dependency
function Parent() {
  const handleClick = useCallback(() => {
    doSomething();
  }, []);

  return <button onClick={handleClick}>Click</button>;
}
// Just use: onClick={() => doSomething()}
```

3. **When to use React.memo**
```tsx
// ✓ Good: Expensive child with stable props
const ExpensiveChart = memo(function ExpensiveChart({ data }) {
  return <Chart data={data} />;  // Complex rendering
});

// ✓ Good: Frequently re-rendered parent
function ChatApp() {
  const [messages, setMessages] = useState([]);
  // Header doesn't need message updates
  return (
    <>
      <MemoizedHeader />
      <MessageList messages={messages} />
    </>
  );
}

// ✗ Bad: Simple component with changing props
const SimpleText = memo(({ text }) => <span>{text}</span>);
// Overhead not worth it
```

4. **Custom comparison for memo**
```tsx
// Deep comparison when needed
const MemoizedItem = memo(Item, (prev, next) => {
  return (
    prev.id === next.id &&
    prev.name === next.name &&
    prev.status === next.status
  );
});

// Or use JSON comparison (caution: expensive)
const MemoizedItem = memo(Item, (prev, next) => {
  return JSON.stringify(prev) === JSON.stringify(next);
});
```

5. **Zustand selectors**
```tsx
// Bad: Subscribes to entire store
const { user, bots, settings } = useStore();

// Good: Subscribe only to what you need
const user = useStore(state => state.user);
const botCount = useStore(state => state.bots.length);

// Good: Shallow equality for objects
const filters = useStore(
  state => state.filters,
  shallow
);
```

### Memoization Decision Tree

```
Should I memoize?
│
├─ Is it expensive (> 1ms)? ─── No ──> Don't memoize
│         │
│        Yes
│         │
├─ Does it re-run often? ─── No ──> Don't memoize
│         │
│        Yes
│         │
├─ Are dependencies stable? ─── No ──> Fix dependencies first
│         │
│        Yes
│         │
└─────> Memoize it
```

## Verification

```tsx
// Profile before and after
console.time('render');
// Component render
console.timeEnd('render');

// Check memo is working
const MemoizedChild = memo(Child);
// React DevTools should show "Did not render"
```

## Prevention

- Profile before optimizing
- Understand when memo helps
- Keep dependencies minimal
- Use Zustand selectors
- Review memo usage in code review

## Project-Specific Notes

**BotFacebook Context:**
- Memoize: Chart components, Message list items
- Don't memoize: Simple UI components
- Zustand: Always use selectors
- Profile dashboard and chat views
