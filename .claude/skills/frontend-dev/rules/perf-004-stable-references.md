---
id: perf-004-stable-references
title: Stable References in Dependencies
impact: CRITICAL
impactDescription: "Prevents infinite loops and unnecessary effect executions"
category: perf
tags: [hooks, dependencies, useEffect, useCallback, references]
relatedRules: [gotcha-002-infinite-rerenders, perf-001-memoization]
---

## Why This Matters

React hooks compare dependencies by reference, not value. Objects and arrays created inline always have new references, causing hooks to run on every render. This leads to infinite loops, unnecessary API calls, and performance degradation.

Understanding reference stability is fundamental to writing correct React code.

## Bad Example

```tsx
// Problem 1: Object in useEffect dependencies
function BotFetcher({ botId, filters }) {
  const [data, setData] = useState(null);

  useEffect(() => {
    // Runs EVERY render because filters is new object each time!
    fetchBot(botId, { ...filters, timestamp: Date.now() }).then(setData);
  }, [botId, filters]); // filters: {} !== {}

  return <BotDisplay data={data} />;
}

// Usage that causes infinite loop:
<BotFetcher botId="123" filters={{ status: 'active' }} />
// filters is new object on every parent render!

// Problem 2: Array in dependencies
function TaggedItems({ tags }) {
  useEffect(() => {
    fetchItems(tags);
  }, [tags]); // tags: ['a', 'b'] !== ['a', 'b']
}

// Problem 3: Function in dependencies
function SearchComponent({ onSearch }) {
  const [query, setQuery] = useState('');

  useEffect(() => {
    onSearch(query);
  }, [query, onSearch]); // onSearch changes every parent render!
}

// Problem 4: Computed value in dependencies
function FilteredList({ items, filterFn }) {
  const filteredItems = items.filter(filterFn); // New array every render

  useEffect(() => {
    logAnalytics('items_displayed', filteredItems.length);
  }, [filteredItems]); // Runs every render!
}
```

**Why it's wrong:**
- New object/array references on every render
- `===` comparison fails even for identical content
- useEffect/useCallback/useMemo run unnecessarily
- Can cause infinite loops if effect updates state

## Good Example

```tsx
// Solution 1: Memoize objects passed to children
function ParentComponent() {
  const [status, setStatus] = useState('active');

  // Stable reference - only changes when status changes
  const filters = useMemo(() => ({
    status,
    limit: 10,
  }), [status]);

  return <BotFetcher filters={filters} />;
}

// Solution 2: Use primitive values in deps when possible
function BotFetcher({ botId, status, limit }) {
  useEffect(() => {
    fetchBot(botId, { status, limit });
  }, [botId, status, limit]); // Primitives compared by value
}

// Solution 3: Memoize callbacks passed to children
function SearchContainer() {
  const [results, setResults] = useState([]);

  const handleSearch = useCallback((query: string) => {
    searchApi(query).then(setResults);
  }, []); // Stable reference

  return <SearchInput onSearch={handleSearch} />;
}

// Solution 4: Move constants outside component
const DEFAULT_FILTERS = { status: 'active', limit: 10 };

function BotList() {
  useEffect(() => {
    fetchBots(DEFAULT_FILTERS);
  }, []); // DEFAULT_FILTERS is stable (outside component)
}

// Solution 5: Use JSON comparison for complex objects
function DeepCompareEffect({ config }) {
  const configJson = JSON.stringify(config);

  useEffect(() => {
    const parsedConfig = JSON.parse(configJson);
    doSomethingWith(parsedConfig);
  }, [configJson]); // String comparison works by value
}

// Solution 6: Extract values in hook
function useFetchWithFilters(filters: Filters) {
  // Destructure to primitives
  const { status, limit, sortBy } = filters;

  useEffect(() => {
    fetch({ status, limit, sortBy });
  }, [status, limit, sortBy]); // Primitives are stable
}

// Solution 7: useRef for values that shouldn't trigger effects
function AnalyticsTracker({ onEvent }) {
  const onEventRef = useRef(onEvent);

  // Keep ref updated without triggering effect
  useLayoutEffect(() => {
    onEventRef.current = onEvent;
  });

  useEffect(() => {
    const handler = (e: Event) => onEventRef.current(e);
    window.addEventListener('click', handler);
    return () => window.removeEventListener('click', handler);
  }, []); // Empty deps - effect runs once
}

// Solution 8: useMemo for derived arrays
function FilteredList({ items, filterFn }) {
  const filteredItems = useMemo(
    () => items.filter(filterFn),
    [items, filterFn] // Assumes filterFn is stable
  );

  const itemCount = filteredItems.length;

  useEffect(() => {
    logAnalytics('items_displayed', itemCount);
  }, [itemCount]); // Number is primitive - stable
}
```

**Why it's better:**
- `useMemo` creates stable object references
- Primitives are compared by value automatically
- Constants outside components are always stable
- `useRef` holds values without triggering re-renders
- JSON comparison for deep object equality

## Project-Specific Notes

**Common Unstable References in BotFacebook:**

| Source | Fix |
|--------|-----|
| Inline filters: `{ status: 'active' }` | `useMemo` or extract |
| Array props: `['a', 'b']` | Constant or `useMemo` |
| Callbacks: `() => doThing()` | `useCallback` |
| Query options | Extract to constant |

**Quick Reference Check:**
```tsx
// Is this stable?
const x = { a: 1 };           // NO - new object
const x = useMemo(() => ({}), []); // YES - memoized
const x = useRef({}).current; // YES - ref
const x = CONSTANT;           // YES - outside component
const x = 'string';           // YES - primitive
```

**ESLint Plugin:**
```json
// eslint-plugin-react-hooks catches most issues
{
  "rules": {
    "react-hooks/exhaustive-deps": "error"
  }
}
```

## References

- [React Hooks Dependencies](https://react.dev/learn/removing-effect-dependencies)
- [A Complete Guide to useEffect](https://overreacted.io/a-complete-guide-to-useeffect/)
