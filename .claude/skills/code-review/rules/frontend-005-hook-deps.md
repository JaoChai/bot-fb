---
id: frontend-005-hook-deps
title: Correct Hook Dependencies
impact: HIGH
impactDescription: "Wrong dependencies cause stale closures, infinite loops, or missed updates"
category: frontend
tags: [react, hooks, useEffect, dependencies]
relatedRules: [frontend-006-memoization, frontend-007-cleanup]
---

## Why This Matters

Hook dependencies determine when effects run and when callbacks update. Missing deps cause stale data; extra deps cause unnecessary runs; objects cause infinite loops.

## Bad Example

```tsx
// Missing dependency
useEffect(() => {
  fetchData(userId); // userId not in deps
}, []); // Stale: uses initial userId forever

// Object in deps (new reference each render)
useEffect(() => {
  doSomething(options);
}, [options]); // Infinite loop if options = { ... }

// Function in deps
useEffect(() => {
  handleData(data);
}, [handleData]); // Infinite loop if handleData not memoized

// eslint-disable without fix
// eslint-disable-next-line react-hooks/exhaustive-deps
useEffect(() => {
  fetchUser(userId);
}, []);
```

**Why it's wrong:**
- Missing deps: Stale closure
- Object deps: New ref every render
- Function deps: Same problem
- Disabling lint: Hides the bug

## Good Example

```tsx
// All dependencies included
useEffect(() => {
  fetchData(userId);
}, [userId]); // Re-runs when userId changes

// Memoize objects
const options = useMemo(() => ({
  limit: 10,
  sort: sortOrder
}), [sortOrder]);

useEffect(() => {
  doSomething(options);
}, [options]); // Stable reference

// Memoize callbacks
const handleData = useCallback((data: Data) => {
  processData(data, config);
}, [config]);

useEffect(() => {
  handleData(data);
}, [handleData, data]);

// Or use ref for truly stable values
const configRef = useRef(config);
configRef.current = config;

useEffect(() => {
  handleData(data, configRef.current);
}, [data]); // configRef never changes
```

**Why it's better:**
- All dependencies explicit
- Stable object references
- No stale closures
- Predictable behavior

## Review Checklist

- [ ] No eslint-disable for exhaustive-deps
- [ ] Objects in deps are memoized
- [ ] Functions in deps are useCallback
- [ ] Primitive values preferred over objects
- [ ] useRef for values that shouldn't trigger re-run

## Detection

```bash
# Disabled exhaustive-deps
grep -rn "exhaustive-deps" --include="*.tsx" src/

# Empty dependency arrays (suspicious)
grep -rn "}, \[\])" --include="*.tsx" src/

# Objects created inline in deps
grep -B 5 "useEffect\|useMemo\|useCallback" --include="*.tsx" src/ | grep "{ }"
```

## Project-Specific Notes

**BotFacebook Hook Patterns:**

```tsx
// Correct: primitive deps
const { data } = useQuery({
  queryKey: ['bot', botId], // botId is number
  queryFn: () => api.bots.get(botId),
  enabled: !!botId,
});

// Correct: memoized options
const queryOptions = useMemo(() => ({
  limit: pageSize,
  offset: page * pageSize,
}), [pageSize, page]);

// Correct: useCallback for handlers
const handleSend = useCallback(async (message: string) => {
  await sendMessage(conversationId, message);
  refetch();
}, [conversationId, refetch]);

// Correct: ref for WebSocket handler
const onMessageRef = useRef(onMessage);
onMessageRef.current = onMessage;

useEffect(() => {
  const channel = echo.channel(`conversation.${id}`);
  channel.listen('NewMessage', (e) => {
    onMessageRef.current(e.message);
  });
  return () => channel.stopListening('NewMessage');
}, [id]); // onMessage not in deps, uses ref
```
