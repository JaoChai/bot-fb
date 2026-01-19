---
id: frontend-006-memoization
title: Appropriate Memoization
impact: MEDIUM
impactDescription: "Missing memoization causes re-renders; over-memoization adds complexity"
category: frontend
tags: [react, performance, useMemo, useCallback, memo]
relatedRules: [frontend-005-hook-deps, perf-005-render-optimization]
---

## Why This Matters

Memoization prevents unnecessary recalculations and re-renders. Too little causes performance issues; too much adds complexity without benefit.

## Bad Example

```tsx
// Expensive computation every render
function BotStats({ bot }: Props) {
  const stats = calculateExpensiveStats(bot.messages); // Runs every render!
  return <div>{stats.total}</div>;
}

// Object created every render (breaks child memo)
function Parent() {
  const config = { theme: 'dark', size: 'lg' }; // New object each render
  return <MemoizedChild config={config} />; // Child re-renders anyway
}

// Unnecessary memoization (simple value)
const name = useMemo(() => user.name, [user.name]); // Pointless

// Function recreated (breaks memo)
function List({ items, onSelect }) {
  return items.map(item => (
    <MemoizedItem
      key={item.id}
      item={item}
      onClick={() => onSelect(item.id)} // New function each render!
    />
  ));
}
```

**Why it's wrong:**
- Expensive work repeated
- Objects break memo
- Over-memoizing simple values
- Inline functions defeat purpose

## Good Example

```tsx
// Memoize expensive calculations
function BotStats({ bot }: Props) {
  const stats = useMemo(
    () => calculateExpensiveStats(bot.messages),
    [bot.messages]
  );
  return <div>{stats.total}</div>;
}

// Memoize objects passed to memoized children
function Parent() {
  const config = useMemo(() => ({ theme: 'dark', size: 'lg' }), []);
  return <MemoizedChild config={config} />;
}

// Memoize callbacks
function List({ items, onSelect }) {
  const handleClick = useCallback((id: number) => {
    onSelect(id);
  }, [onSelect]);

  return items.map(item => (
    <MemoizedItem
      key={item.id}
      item={item}
      onClick={handleClick}
      itemId={item.id}
    />
  ));
}

// React.memo for expensive components
const MemoizedItem = memo(function Item({ item, onClick, itemId }: Props) {
  return (
    <div onClick={() => onClick(itemId)}>
      {item.name}
    </div>
  );
});
```

**Why it's better:**
- Expensive work cached
- Stable references
- Memo actually works
- Appropriate complexity

## Review Checklist

- [ ] Expensive calculations use `useMemo`
- [ ] Objects passed to memo'd children memoized
- [ ] Callbacks use `useCallback` when passed down
- [ ] `React.memo` on expensive list items
- [ ] No over-memoization of simple values

## Detection

```bash
# Missing memoization (expensive in render)
grep -rn "filter(\|reduce(\|sort(\|map(" --include="*.tsx" src/components/ | grep -v "useMemo"

# Inline objects in JSX
grep -rn "={.*{" --include="*.tsx" src/ | grep -v "style="
```

## Project-Specific Notes

**BotFacebook Memoization Guidelines:**

```tsx
// DO memoize: expensive calculations
const filteredBots = useMemo(
  () => bots.filter(b => b.name.includes(search)).sort(sortFn),
  [bots, search, sortFn]
);

// DO memoize: objects for memoized children
const messageListProps = useMemo(() => ({
  conversationId,
  limit: 50,
}), [conversationId]);

// DO memoize: callbacks for lists
const handleBotSelect = useCallback((id: number) => {
  setSelectedBot(id);
}, []);

// DON'T memoize: simple values
const name = user.name; // Just use directly

// DON'T memoize: values that change every render
// useMemo(() => ({ ...props }), [props]) // Pointless if props always new

// Use React.memo for list items
const ConversationItem = memo(function ConversationItem(props) {
  // ...
});
```
