---
id: frontend-007-cleanup
title: Effect Cleanup Functions
impact: MEDIUM
impactDescription: "Missing cleanup causes memory leaks and stale updates"
category: frontend
tags: [react, useEffect, cleanup, memory-leak]
relatedRules: [frontend-005-hook-deps]
---

## Why This Matters

Effects that set up subscriptions, timers, or async operations must clean up when the component unmounts or deps change. Missing cleanup causes memory leaks and state updates to unmounted components.

## Bad Example

```tsx
// No cleanup for interval
useEffect(() => {
  setInterval(() => {
    setCount(c => c + 1);
  }, 1000);
}, []); // Memory leak: interval runs forever

// No cleanup for subscription
useEffect(() => {
  const channel = echo.channel(`bot.${botId}`);
  channel.listen('message', handleMessage);
}, [botId]); // Memory leak: old listeners pile up

// No abort for fetch
useEffect(() => {
  fetch(`/api/bots/${botId}`)
    .then(res => res.json())
    .then(data => setBotData(data));
}, [botId]); // State update after unmount warning
```

**Why it's wrong:**
- Intervals run after unmount
- Event listeners pile up
- Fetch completes after unmount
- Memory leaks

## Good Example

```tsx
// Cleanup interval
useEffect(() => {
  const id = setInterval(() => {
    setCount(c => c + 1);
  }, 1000);

  return () => clearInterval(id);
}, []);

// Cleanup subscription
useEffect(() => {
  const channel = echo.channel(`bot.${botId}`);
  channel.listen('message', handleMessage);

  return () => {
    channel.stopListening('message');
    echo.leave(`bot.${botId}`);
  };
}, [botId, handleMessage]);

// AbortController for fetch
useEffect(() => {
  const controller = new AbortController();

  fetch(`/api/bots/${botId}`, { signal: controller.signal })
    .then(res => res.json())
    .then(data => setBotData(data))
    .catch(err => {
      if (err.name !== 'AbortError') {
        setError(err);
      }
    });

  return () => controller.abort();
}, [botId]);

// Or use React Query (handles cleanup automatically)
const { data } = useQuery({
  queryKey: ['bot', botId],
  queryFn: () => api.bots.get(botId),
});
```

**Why it's better:**
- Resources cleaned up
- No memory leaks
- No stale state updates
- Proper error handling

## Review Checklist

- [ ] setInterval/setTimeout have clearInterval/clearTimeout
- [ ] Event listeners have removeEventListener
- [ ] WebSocket subscriptions have unsubscribe
- [ ] Fetch requests have AbortController
- [ ] Consider React Query for data fetching

## Detection

```bash
# Effects without cleanup
grep -A 10 "useEffect" --include="*.tsx" src/ | grep -B 5 "}, \[" | grep -v "return"

# Intervals without cleanup
grep -rn "setInterval\|setTimeout" --include="*.tsx" src/ | xargs -I {} grep -L "clearInterval\|clearTimeout" {}

# Subscriptions without cleanup
grep -rn "addEventListener\|.on(\|.listen(" --include="*.tsx" src/
```

## Project-Specific Notes

**BotFacebook Cleanup Patterns:**

```tsx
// WebSocket channel cleanup
useEffect(() => {
  const channel = echo.private(`conversation.${conversationId}`);

  channel.listen('NewMessage', (e: { message: Message }) => {
    queryClient.setQueryData(
      ['messages', conversationId],
      (old: Message[]) => [...old, e.message]
    );
  });

  return () => {
    channel.stopListening('NewMessage');
    echo.leave(`private-conversation.${conversationId}`);
  };
}, [conversationId, queryClient]);

// Presence channel cleanup
useEffect(() => {
  const channel = echo.join(`presence-bot.${botId}`);

  channel
    .here((users) => setActiveUsers(users))
    .joining((user) => setActiveUsers(u => [...u, user]))
    .leaving((user) => setActiveUsers(u => u.filter(x => x.id !== user.id)));

  return () => echo.leave(`presence-bot.${botId}`);
}, [botId]);

// Scroll observer cleanup
useEffect(() => {
  const observer = new IntersectionObserver(
    ([entry]) => entry.isIntersecting && loadMore(),
    { threshold: 0.1 }
  );

  if (loadMoreRef.current) {
    observer.observe(loadMoreRef.current);
  }

  return () => observer.disconnect();
}, [loadMore]);
```
