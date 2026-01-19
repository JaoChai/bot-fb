---
id: gotcha-002-infinite-rerenders
title: Infinite Re-render Prevention
impact: CRITICAL
impactDescription: "Prevents browser freezes and performance degradation from render loops"
category: gotcha
tags: [performance, hooks, useEffect, useMemo, dependencies]
relatedRules: [perf-001-memoization, perf-004-stable-references]
---

## Why This Matters

Infinite re-renders freeze the browser and crash the application. They occur when a component's state or props change on every render, creating an endless loop. This is especially common with object/array dependencies in useEffect and useCallback.

Objects and arrays are compared by reference in JavaScript. Creating new objects inline in dependencies will always trigger re-renders because `{} !== {}` even with identical content.

## Bad Example

```tsx
// Problem 1: Object in useEffect dependency
function BotDetail({ botId }: { botId: string }) {
  const [bot, setBot] = useState(null);

  const options = { includeStats: true }; // New object every render!

  useEffect(() => {
    fetchBot(botId, options).then(setBot);
  }, [botId, options]); // options changes every render = infinite loop!

  return <div>{bot?.name}</div>;
}

// Problem 2: Inline object in child prop
function Parent() {
  const [count, setCount] = useState(0);

  return (
    <Child
      config={{ theme: 'dark' }} // New object every render!
      onClick={() => setCount(c => c + 1)} // New function every render!
    />
  );
}
```

**Why it's wrong:**
- `options` creates a new object reference on every render
- `[botId, options]` dependency array sees "new" options each time
- useEffect runs on every render, potentially setting state, causing more renders
- Inline objects and functions in JSX cause child re-renders even with React.memo

## Good Example

```tsx
// Solution 1: Memoize objects used in dependencies
function BotDetail({ botId }: { botId: string }) {
  const [bot, setBot] = useState(null);

  // Stable reference - only changes if includeStats changes
  const options = useMemo(() => ({ includeStats: true }), []);

  useEffect(() => {
    fetchBot(botId, options).then(setBot);
  }, [botId, options]); // Now stable!

  return <div>{bot?.name}</div>;
}

// Solution 2: Define constants outside component or memoize
const CONFIG = { theme: 'dark' }; // Defined once outside

function Parent() {
  const [count, setCount] = useState(0);

  const handleClick = useCallback(() => {
    setCount(c => c + 1);
  }, []);

  return (
    <Child
      config={CONFIG}
      onClick={handleClick}
    />
  );
}

// Solution 3: Primitive dependencies when possible
function BotDetail({ botId }: { botId: string }) {
  const [bot, setBot] = useState(null);
  const includeStats = true; // Primitive - stable!

  useEffect(() => {
    fetchBot(botId, { includeStats }).then(setBot);
  }, [botId, includeStats]); // Primitives are compared by value

  return <div>{bot?.name}</div>;
}
```

**Why it's better:**
- `useMemo` creates stable reference that persists across renders
- Constants outside component never change reference
- `useCallback` creates stable function reference
- Primitive values (strings, numbers, booleans) are compared by value, not reference

## Project-Specific Notes

**Common Culprits in BotFacebook:**
- Query options objects in custom hooks
- Filter objects passed to API calls
- Event handlers defined inline in JSX
- Config objects for WebSocket connections

**Debug Tip:**
```tsx
// Add this to detect infinite loops
useEffect(() => {
  console.log('Effect running', { botId, options });
}, [botId, options]);

// Use React DevTools Profiler to see re-render causes
```

## References

- [React Hooks FAQ: Infinite Loops](https://react.dev/learn/removing-effect-dependencies)
- Related rule: perf-004-stable-references
- Related rule: perf-001-memoization
