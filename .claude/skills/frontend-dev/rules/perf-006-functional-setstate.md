---
id: perf-006-functional-setstate
title: Use Functional setState to Avoid Stale Closures
impact: HIGH
impactDescription: "Prevents bugs from stale state - especially in async operations"
category: perf
tags: [state, hooks, closures, async]
relatedRules: [gotcha-002-infinite-rerenders, perf-004-stable-references]
---

## Why This Matters

When you use `setState(newValue)` in async callbacks or event handlers, you might be using a stale value from when the closure was created. Using `setState(prev => newValue)` always gives you the latest state.

## Bad Example

```tsx
// Problem: Stale closure in async operation
function Counter() {
  const [count, setCount] = useState(0);

  const handleClick = async () => {
    // count is captured when handleClick is created
    await someAsyncOperation();
    setCount(count + 1);  // count might be stale!
  };

  const incrementThreeTimes = () => {
    setCount(count + 1);  // All three use same stale count
    setCount(count + 1);  // Result: count + 1, not count + 3
    setCount(count + 1);
  };

  return <button onClick={handleClick}>{count}</button>;
}
```

**Why it's wrong:**
- `count` is captured at render time
- Async delays mean count may have changed
- Multiple setCount calls batch with same stale value

## Good Example

```tsx
// Solution: Functional updates
function Counter() {
  const [count, setCount] = useState(0);

  const handleClick = async () => {
    await someAsyncOperation();
    setCount(prev => prev + 1);  // Always uses latest value
  };

  const incrementThreeTimes = () => {
    setCount(prev => prev + 1);  // 0 → 1
    setCount(prev => prev + 1);  // 1 → 2
    setCount(prev => prev + 1);  // 2 → 3
  };

  return <button onClick={handleClick}>{count}</button>;
}
```

**Why it's better:**
- `prev` is always the latest state value
- Works correctly with batched updates
- No stale closure issues

## Common Scenarios

```tsx
// Toggling boolean
// Bad
setIsOpen(!isOpen);
// Good
setIsOpen(prev => !prev);

// Adding to array
// Bad
setItems([...items, newItem]);
// Good
setItems(prev => [...prev, newItem]);

// Updating object
// Bad
setUser({ ...user, name: newName });
// Good
setUser(prev => ({ ...prev, name: newName }));

// Removing from array
// Bad
setItems(items.filter(i => i.id !== id));
// Good
setItems(prev => prev.filter(i => i.id !== id));
```

## With Zustand

```tsx
// Zustand also supports functional updates
const useStore = create<State>((set) => ({
  count: 0,
  increment: () => set(state => ({ count: state.count + 1 })),
  // Not: set({ count: get().count + 1 })
}));
```

## When Direct setState Is OK

```tsx
// When value doesn't depend on previous state
const [name, setName] = useState('');
setName('John');  // OK - not based on prev

// When you're replacing entire state
const [user, setUser] = useState<User | null>(null);
setUser(newUser);  // OK - complete replacement
```

## Project-Specific Notes

Common in BotFacebook:
- Chat message list updates
- Conversation status toggles
- Form field arrays (knowledge base items)

## References

- [React useState - Functional Updates](https://react.dev/reference/react/useState#updating-state-based-on-the-previous-state)
- Related rule: [gotcha-002-infinite-rerenders](gotcha-002-infinite-rerenders.md)
