---
id: perf-001-memoization
title: Memoization Guidelines
impact: HIGH
impactDescription: "Prevents expensive re-computations and unnecessary child re-renders"
category: perf
tags: [useMemo, useCallback, React.memo, performance, optimization]
relatedRules: [gotcha-002-infinite-rerenders, perf-004-stable-references]
---

## Why This Matters

Memoization caches results so they don't need to be recalculated on every render. `useMemo` caches computed values, `useCallback` caches functions, and `React.memo` caches component renders.

However, memoization has overhead - incorrect usage can hurt performance rather than help.

## Bad Example

```tsx
// Problem 1: Memoizing everything (premature optimization)
function SimpleComponent({ name }) {
  // Unnecessary - string concatenation is cheap
  const greeting = useMemo(() => `Hello, ${name}!`, [name]);

  // Unnecessary - simple inline handler
  const handleClick = useCallback(() => {
    console.log('clicked');
  }, []);

  return <button onClick={handleClick}>{greeting}</button>;
}

// Problem 2: Missing dependencies
function FilteredList({ items, filter }) {
  // Bug: doesn't update when filter changes!
  const filtered = useMemo(
    () => items.filter(i => i.type === filter),
    [items] // Missing filter!
  );

  return <List items={filtered} />;
}

// Problem 3: Memoizing inline objects defeats the purpose
function ParentComponent() {
  const config = useMemo(() => ({ theme: 'dark' }), []);

  return <ChildComponent config={config} options={{ enabled: true }} />;
  // options creates new object every render!
}

// Problem 4: useCallback without React.memo on child
function Parent() {
  const handleClick = useCallback(() => {
    doSomething();
  }, []);

  return <Child onClick={handleClick} />;
}

// Child re-renders anyway without memo!
function Child({ onClick }) {
  return <button onClick={onClick}>Click</button>;
}
```

**Why it's wrong:**
- Memoizing cheap operations adds overhead without benefit
- Missing dependencies cause stale values (bugs)
- Partially memoizing props is ineffective
- `useCallback` without `React.memo` is usually pointless

## Good Example

```tsx
// Solution 1: Memoize expensive computations
function DataGrid({ rows, sortConfig }) {
  // Sorting large arrays is expensive - worth memoizing
  const sortedRows = useMemo(() => {
    console.log('Sorting rows'); // Only runs when deps change
    return [...rows].sort((a, b) =>
      sortConfig.direction === 'asc'
        ? a[sortConfig.key].localeCompare(b[sortConfig.key])
        : b[sortConfig.key].localeCompare(a[sortConfig.key])
    );
  }, [rows, sortConfig.key, sortConfig.direction]);

  return <Table rows={sortedRows} />;
}

// Solution 2: useCallback + React.memo for child optimization
const MemoizedChild = memo(function Child({
  onClick,
  data,
}: {
  onClick: () => void;
  data: Item;
}) {
  console.log('Child rendered'); // Only when props change
  return (
    <button onClick={onClick}>
      {data.name}
    </button>
  );
});

function Parent({ items }) {
  const [selected, setSelected] = useState<string | null>(null);

  // Stable function reference for memoized child
  const handleSelect = useCallback((id: string) => {
    setSelected(id);
  }, []);

  return (
    <div>
      {items.map((item) => (
        <MemoizedChild
          key={item.id}
          data={item}
          onClick={() => handleSelect(item.id)}
          // Still creates new function! Need another approach
        />
      ))}
    </div>
  );
}

// Solution 3: Move callback into child for list items
const ListItem = memo(function ListItem({
  item,
  onSelect,
}: {
  item: Item;
  onSelect: (id: string) => void;
}) {
  const handleClick = useCallback(() => {
    onSelect(item.id);
  }, [onSelect, item.id]);

  return (
    <button onClick={handleClick}>
      {item.name}
    </button>
  );
});

function List({ items }) {
  const [selected, setSelected] = useState<string | null>(null);

  // Single stable function
  const handleSelect = useCallback((id: string) => {
    setSelected(id);
  }, []);

  return (
    <div>
      {items.map((item) => (
        <ListItem
          key={item.id}
          item={item}
          onSelect={handleSelect} // Same reference for all items
        />
      ))}
    </div>
  );
}

// Solution 4: useMemo for derived component props
function Dashboard({ data }) {
  const chartConfig = useMemo(() => ({
    labels: data.map(d => d.date),
    values: data.map(d => d.value),
    options: { responsive: true, animation: false },
  }), [data]);

  return <Chart config={chartConfig} />;
}

// Solution 5: When NOT to memoize
function SimpleForm({ onSubmit }) {
  const [value, setValue] = useState('');

  // Don't memoize simple state updates
  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setValue(e.target.value);
  };

  // Don't memoize cheap renders
  return (
    <form onSubmit={() => onSubmit(value)}>
      <input value={value} onChange={handleChange} />
      <button type="submit">Submit</button>
    </form>
  );
}
```

**Why it's better:**
- Memoization applied only where it helps
- Dependencies are complete and correct
- `React.memo` wraps children that receive memoized props
- List items handle their own callbacks
- Simple components stay simple

## Project-Specific Notes

**When to Memoize in BotFacebook:**

| Scenario | useMemo | useCallback | React.memo |
|----------|---------|-------------|------------|
| Sorting/filtering large lists | Yes | - | - |
| Chart data transformation | Yes | - | - |
| List item callbacks | - | Yes | Yes (item) |
| Event handlers passed to memo'd children | - | Yes | - |
| Simple form handlers | No | No | No |

**Measure Before Optimizing:**
```tsx
// React DevTools Profiler shows render times
// Use console.time for specific operations
console.time('filter');
const result = expensiveFilter(items);
console.timeEnd('filter');
```

## References

- [When to useMemo and useCallback](https://kentcdodds.com/blog/usememo-and-usecallback)
- [React.memo Guide](https://react.dev/reference/react/memo)
