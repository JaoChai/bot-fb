---
id: perf-007-derive-state-in-render
title: Derive State During Render Instead of Effects
impact: HIGH
impactDescription: "Eliminates unnecessary re-renders and effect cycles"
category: perf
tags: [state, effects, derived-state, performance]
relatedRules: [perf-001-memoization, gotcha-002-infinite-rerenders]
---

## Why This Matters

Using `useEffect` to update state based on props or other state causes extra re-renders: first render → effect runs → setState → second render. Computing derived values directly during render is faster and simpler.

## Bad Example

```tsx
// Problem: Unnecessary effect and state
function FilteredList({ items, filter }: Props) {
  const [filteredItems, setFilteredItems] = useState<Item[]>([]);

  useEffect(() => {
    // Effect runs AFTER render, then triggers another render
    setFilteredItems(items.filter(item => item.name.includes(filter)));
  }, [items, filter]);

  return <List items={filteredItems} />;
  // Renders twice: once with stale data, once with filtered
}
```

**Why it's wrong:**
- Effect runs after render completes
- setState triggers another render cycle
- User might see flash of unfiltered content
- More complex code for simple derivation

## Good Example

```tsx
// Solution: Derive during render
function FilteredList({ items, filter }: Props) {
  // Computed directly - no extra render
  const filteredItems = items.filter(item => item.name.includes(filter));

  return <List items={filteredItems} />;
  // Single render with correct data
}

// With memoization for expensive computations
function FilteredList({ items, filter }: Props) {
  const filteredItems = useMemo(
    () => items.filter(item => item.name.includes(filter)),
    [items, filter]
  );

  return <List items={filteredItems} />;
}
```

**Why it's better:**
- Single render cycle
- No effect complexity
- Simpler, more predictable code
- Better performance

## More Examples

```tsx
// Bad: Effect to compute total
const [total, setTotal] = useState(0);
useEffect(() => {
  setTotal(items.reduce((sum, i) => sum + i.price, 0));
}, [items]);

// Good: Derive directly
const total = items.reduce((sum, i) => sum + i.price, 0);
// Or memoize if items is large
const total = useMemo(
  () => items.reduce((sum, i) => sum + i.price, 0),
  [items]
);

// Bad: Effect to format data
const [formattedDate, setFormattedDate] = useState('');
useEffect(() => {
  setFormattedDate(format(date, 'PP'));
}, [date]);

// Good: Derive directly
const formattedDate = format(date, 'PP');

// Bad: Effect to check validation
const [isValid, setIsValid] = useState(false);
useEffect(() => {
  setIsValid(email.includes('@') && password.length >= 8);
}, [email, password]);

// Good: Derive directly
const isValid = email.includes('@') && password.length >= 8;
```

## When Effects ARE Needed

```tsx
// Side effects that interact with external systems
useEffect(() => {
  // Sync with localStorage
  localStorage.setItem('theme', theme);
}, [theme]);

// Subscriptions
useEffect(() => {
  const sub = eventSource.subscribe(handleEvent);
  return () => sub.unsubscribe();
}, []);

// DOM measurements
useEffect(() => {
  const height = ref.current?.getBoundingClientRect().height;
  setMeasuredHeight(height);
}, []);
```

## Project-Specific Notes

Common derived values in BotFacebook:
- Filtered conversation list
- Unread message count
- Bot status indicators
- Form validation states

## References

- [React: You Might Not Need an Effect](https://react.dev/learn/you-might-not-need-an-effect)
- Related rule: [perf-001-memoization](perf-001-memoization.md)
